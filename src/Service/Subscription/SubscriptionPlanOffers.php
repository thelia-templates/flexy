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

use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Subscription\Exception\SubscriptionPlanPriceMissingException;
use Thelia\Domain\Subscription\SubscriptionPlanPricing;
use Thelia\Domain\Taxation\TaxEngine\TaxEngine;
use Thelia\Model\Currency;
use Thelia\Model\Customer;
use Thelia\Model\ProductQuery;
use Thelia\Model\SubscriptionPlan;
use Thelia\Model\SubscriptionPlanQuery;

/**
 * The plans of a product, as its sheet offers them: scalars only, since they travel in a LiveProp.
 *
 * The price of one installment is the core's (SubscriptionPlanPricing, untaxed, in the currency the
 * visitor browses in). The taxed figure is the one the core's tax calculator gives for the tax rule of
 * the product in the country of the visitor: the same call the cart line makes (TaxEngine::getDeliveryCountry()
 * and Product::getTaxedPrice()). Nothing is calculated here, and the page picks one of the two figures with
 * the display of the group of the visitor, as it does for the price of the product. A plan with no price in the
 * currency of the visitor cannot be bought and is left out.
 *
 * Only built when the core carries subscriptions (SubscriptionServicesPass drops the service otherwise).
 */
final readonly class SubscriptionPlanOffers
{
    public function __construct(
        private SubscriptionPlanPricing $pricing,
        private TaxEngine $taxEngine,
        private LangService $langService,
        private RequestStack $requestStack,
        private SecurityContext $securityContext,
    ) {
    }

    /**
     * @return list<array{id: int, title: string, periodUnit: string, periodCount: int, trialDays: int, installmentCount: ?int, commitmentCount: ?int, pausable: bool, priceUntaxed: float, priceTaxed: float}>
     */
    public function forProduct(int $productId): array
    {
        $plans = SubscriptionPlanQuery::create()
            ->filterByProductId($productId)
            ->filterByVisible(1)
            ->orderByPosition()
            ->find();

        if (0 === $plans->count()) {
            return [];
        }

        $product = ProductQuery::create()->findPk($productId);

        if (null === $product) {
            return [];
        }

        $currency = $this->currency();
        $country = $this->taxEngine->getDeliveryCountry();
        $locale = $this->langService->getLocale();
        $offers = [];

        foreach ($plans as $plan) {
            \assert($plan instanceof SubscriptionPlan);

            try {
                $untaxed = (float) $this->pricing->priceOf($plan, $currency);
            } catch (SubscriptionPlanPriceMissingException) {
                continue;
            }

            $offers[] = [
                'id' => (int) $plan->getId(),
                'title' => (string) $plan->setLocale($locale)->getTitle(),
                'periodUnit' => (string) $plan->getPeriodUnit(),
                'periodCount' => (int) $plan->getPeriodCount(),
                'trialDays' => (int) $plan->getTrialDays(),
                'installmentCount' => null === $plan->getInstallmentCount() ? null : (int) $plan->getInstallmentCount(),
                'commitmentCount' => null === $plan->getCommitmentCount() ? null : (int) $plan->getCommitmentCount(),
                'pausable' => 1 === (int) $plan->getPausable(),
                'priceUntaxed' => $untaxed,
                'priceTaxed' => (float) $product->getTaxedPrice($country, $untaxed),
            ];
        }

        return $offers;
    }

    /**
     * A visitor who is not signed in (a guest of the checkout is not either) is invited to sign in.
     */
    public function visitorMaySubscribe(): bool
    {
        if (!$this->securityContext->hasAuthenticatedCustomerUser()) {
            return false;
        }

        $customer = $this->securityContext->getCustomerUser();

        return $customer instanceof Customer && !$customer->isGuest();
    }

    private function currency(): Currency
    {
        $session = $this->requestStack->getCurrentRequest()?->getSession();

        return $session instanceof Session ? $session->getCurrency() : Currency::getDefaultCurrency();
    }
}
