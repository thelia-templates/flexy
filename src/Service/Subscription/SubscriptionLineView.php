<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlexyBundle\Service\Subscription;

use FlexyBundle\Service\PriceDisplay;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Subscription\Exception\SubscriptionPlanPriceMissingException;
use Thelia\Domain\Subscription\SubscriptionFactory;
use Thelia\Domain\Subscription\SubscriptionPlanPricing;
use Thelia\Domain\Taxation\TaxEngine\TaxEngine;
use Thelia\Model\Cart;
use Thelia\Model\CartItem;
use Thelia\Model\Currency;
use Thelia\Model\SubscriptionPlan;
use Thelia\Model\SubscriptionPlanQuery;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * What a cart line bought on a plan says about it, for the cart and the payment step: the period, the
 * trial and the first installment date, the commitment, and the price of one installment as the buyer
 * reads prices (taxed or not, from the core's calculator: see SubscriptionPlanOffers).
 *
 * The cart line itself is priced at 0 while the plan has a trial: the figure of an installment lives on
 * the plan, never on the line. Codes are kept, not sentences: the template translates them.
 */
final readonly class SubscriptionLineView
{
    public function __construct(
        private SubscriptionPlanPricing $pricing,
        private TaxEngine $taxEngine,
        private PriceDisplay $priceDisplay,
        private RequestStack $requestStack,
        private SubscriptionFactory $subscriptionFactory,
    ) {
    }

    /**
     * The day the first installment of a plan with a trial will be charged if the order is paid now: the date billing
     * will write, from the calendar of the core. Null without a trial: the order paid is the first installment.
     */
    public function firstInstallmentOn(SubscriptionPlan $plan): ?string
    {
        if ((int) $plan->getTrialDays() < 1) {
            return null;
        }

        return $this->subscriptionFactory->firstBillingDateOf($plan)->format('Y-m-d');
    }

    /**
     * @return array{planId: int, periodUnit: string, periodCount: int, trialDays: int, firstInstallmentOn: ?string, installmentCount: ?int, commitmentCount: ?int, pausable: bool, price: ?\FlexyBundle\DTO\DisplayedPrice}|null
     */
    public function ofLine(CartItem $line): ?array
    {
        $planId = $line->getSubscriptionPlanId();

        if (null === $planId) {
            return null;
        }

        $plan = SubscriptionPlanQuery::create()->findPk($planId);

        if (null === $plan) {
            return null;
        }

        $trialDays = (int) $plan->getTrialDays();
        $price = null;

        try {
            $untaxed = (float) $this->pricing->priceOf($plan, $this->currency());
            $price = $this->priceDisplay->pick(
                $this->priceDisplay->forVisitor(),
                $untaxed,
                (float) $line->getProduct()->getTaxedPrice($this->taxEngine->getDeliveryCountry(), $untaxed),
            );
        } catch (SubscriptionPlanPriceMissingException) {
        }

        return [
            'planId' => $planId,
            'periodUnit' => (string) $plan->getPeriodUnit(),
            'periodCount' => (int) $plan->getPeriodCount(),
            'trialDays' => $trialDays,
            'firstInstallmentOn' => $this->firstInstallmentOn($plan),
            'installmentCount' => null === $plan->getInstallmentCount() ? null : (int) $plan->getInstallmentCount(),
            'commitmentCount' => null === $plan->getCommitmentCount() ? null : (int) $plan->getCommitmentCount(),
            'pausable' => 1 === (int) $plan->getPausable(),
            'price' => $price,
        ];
    }

    /**
     * The plan line of a cart, when it holds one (the core allows one, alone in its cart).
     *
     * @return array<string, mixed>|null
     */
    public function ofCart(Cart $cart): ?array
    {
        foreach ($cart->getCartItems() as $line) {
            $view = $this->ofLine($line);

            if (null !== $view) {
                return $view + ['quantity' => (int) $line->getQuantity()];
            }
        }

        return null;
    }

    private function currency(): Currency
    {
        $session = $this->requestStack->getCurrentRequest()?->getSession();

        return $session instanceof Session ? $session->getCurrency() : Currency::getDefaultCurrency();
    }
}
