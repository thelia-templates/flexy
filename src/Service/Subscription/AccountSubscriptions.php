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

use FlexyBundle\Service\AccountPaymentMethods;
use FlexyBundle\Service\PriceDisplay;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Payment\CustomerPaymentMethodService;
use Thelia\Domain\Payment\Exception\CustomerPaymentMethodException;
use Thelia\Domain\Subscription\BillingCalendar;
use Thelia\Domain\Subscription\Enum\ActorType;
use Thelia\Domain\Subscription\Enum\PeriodUnit;
use Thelia\Domain\Subscription\Enum\SubscriptionStatus;
use Thelia\Domain\Subscription\Exception\SubscriptionException;
use Thelia\Domain\Subscription\SubscriptionManager;
use Thelia\Domain\Taxation\TaxEngine\TaxEngine;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Customer;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Subscription;
use Thelia\Model\SubscriptionOrderQuery;
use Thelia\Model\SubscriptionPlanQuery;
use Thelia\Model\SubscriptionQuery;

/**
 * The subscriptions of the signed-in customer, as the account pages read them, and what the customer does to them.
 *
 * Every read and every write is scoped to the customer it is given: a subscription of someone else is read as one that
 * does not exist (findOwned() answers null, the controller answers 404). The writes go through SubscriptionManager with
 * the customer as actor. A refusal of the state (a subscription already cancelled, a plan that cannot be paused) comes
 * back as a code the template turns into a sentence, never as an exception.
 */
final readonly class AccountSubscriptions
{
    public const REFUSED_STATE = 'state';
    public const REFUSED_METHOD = 'method';

    public function __construct(
        private SubscriptionManager $manager,
        private BillingCalendar $calendar,
        private CustomerPaymentMethodService $paymentMethodService,
        private AccountPaymentMethods $accountPaymentMethods,
        private PriceDisplay $priceDisplay,
        private TaxEngine $taxEngine,
        private LangService $langService,
    ) {
    }

    /**
     * @return list<array<string, mixed>> the customer's subscriptions, the running ones first then the most recent
     */
    public function listFor(Customer $customer): array
    {
        $views = [];

        foreach (SubscriptionQuery::create()->filterByCustomerId($customer->getId())->orderById('DESC')->find() as $subscription) {
            $views[] = $this->view($subscription);
        }

        usort($views, static fn (array $a, array $b): int => (int) $a['finished'] <=> (int) $b['finished']);

        return $views;
    }

    /**
     * Whether the menu offers the page: the shop sells something by subscription, or the customer holds one still.
     */
    public function isOfferedTo(Customer $customer): bool
    {
        return SubscriptionQuery::create()->filterByCustomerId($customer->getId())->exists()
            || SubscriptionPlanQuery::create()->filterByVisible(1)->exists();
    }

    public function findOwned(Customer $customer, int $id): ?Subscription
    {
        return SubscriptionQuery::create()->filterById($id)->filterByCustomerId($customer->getId())->findOne();
    }

    /**
     * @return array<string, mixed>
     */
    public function view(Subscription $subscription): array
    {
        $status = SubscriptionStatus::from((string) $subscription->getStatus());
        $saleElements = $subscription->getProductSaleElements();
        $product = $saleElements?->getProduct();
        $unitPrice = (float) $subscription->getUnitPrice();
        $price = $this->priceDisplay->pick(
            $this->priceDisplay->forVisitor(),
            $unitPrice,
            null === $product ? $unitPrice : (float) $product->getTaxedPrice($this->taxEngine->getDeliveryCountry(), $unitPrice),
        );

        return [
            'id' => (int) $subscription->getId(),
            'ref' => (string) $subscription->getRef(),
            'status' => $status->value,
            'finished' => \in_array($status, [SubscriptionStatus::Cancelled, SubscriptionStatus::Ended], true),
            'productTitle' => null === $product ? (string) $subscription->getRef() : (string) $product->setLocale($this->langService->getLocale())->getTitle(),
            'productUrl' => $product?->getUrl($this->langService->getLocale()),
            'quantity' => (int) $subscription->getQuantity(),
            'periodUnit' => (string) $subscription->getPeriodUnit(),
            'periodCount' => (int) $subscription->getPeriodCount(),
            'installmentCount' => null === $subscription->getInstallmentCount() ? null : (int) $subscription->getInstallmentCount(),
            'commitmentCount' => null === $subscription->getCommitmentCount() ? null : (int) $subscription->getCommitmentCount(),
            'installmentsBilled' => (int) $subscription->getInstallmentsBilled(),
            'pausable' => 1 === (int) $subscription->getPausable(),
            'price' => $price,
            'currencyCode' => (string) (CurrencyQuery::create()->findPk($subscription->getCurrencyId())?->getCode() ?? ''),
            'nextBillingAt' => $subscription->getNextBillingAt('Y-m-d'),
            'currentPeriodEnd' => $subscription->getCurrentPeriodEnd('Y-m-d'),
            'cancelAt' => $subscription->getCancelAt('Y-m-d'),
            'paymentMethodLabel' => $subscription->getCustomerPaymentMethod()?->getLabel(),
            'canPause' => 1 === (int) $subscription->getPausable() && SubscriptionStatus::Active === $status,
            'canResume' => SubscriptionStatus::Paused === $status,
            'canCancel' => $status->canBecome(SubscriptionStatus::Cancelled),
            'canChangePaymentMethod' => !$status->isFinal() && SubscriptionStatus::Cancelled !== $status,
        ];
    }

    /**
     * @return list<array{number: int, state: string, orderRef: ?string, orderId: ?int, attemptCount: int}>
     */
    public function installmentsOf(Subscription $subscription): array
    {
        $installments = [];

        foreach (SubscriptionOrderQuery::create()->filterBySubscriptionId($subscription->getId())->orderByInstallmentNumber()->find() as $installment) {
            $order = $installment->getOrder();
            $installments[] = [
                'number' => (int) $installment->getInstallmentNumber(),
                'state' => (string) $installment->getState(),
                'orderRef' => $order?->getRef(),
                'orderId' => $order?->getId(),
                'attemptCount' => (int) $installment->getAttemptCount(),
            ];
        }

        return $installments;
    }

    /**
     * The date the customer is told a cancellation takes effect, before they confirm: the end of the paid period, or the
     * end of the commitment when it is later. The same rule SubscriptionManager::cancel() writes into `cancel_at`
     * (a test keeps the two equal).
     */
    public function cancellationDate(Subscription $subscription): \DateTimeImmutable
    {
        $periodEnd = $subscription->getCurrentPeriodEnd();
        $periodEnd = null === $periodEnd ? new \DateTimeImmutable() : \DateTimeImmutable::createFromInterface($periodEnd);
        $remaining = (int) $subscription->getCommitmentCount() - (int) $subscription->getInstallmentsBilled();

        if ($remaining <= 0) {
            return $periodEnd;
        }

        return $this->calendar->advance(
            $periodEnd,
            PeriodUnit::from((string) $subscription->getPeriodUnit()),
            max(1, (int) $subscription->getPeriodCount()),
            (int) $subscription->getBillingAnchorDay(),
            $remaining,
        );
    }

    /**
     * The usable methods the installments could be charged to: the customer's, of the payment module of the subscription.
     *
     * @return list<array{id: int, label: string, paymentModuleTitle: string, expiresAt: ?string, expired: bool, isDefault: bool, usable: bool}>
     */
    public function methodsFor(Customer $customer, Subscription $subscription): array
    {
        $module = ModuleQuery::create()->findPk($subscription->getPaymentModuleId());

        if (null === $module) {
            return [];
        }

        $ofTheModule = [];

        foreach ($this->paymentMethodService->listFor($customer, $module) as $method) {
            $ofTheModule[(int) $method->getId()] = true;
        }

        return array_values(array_filter(
            $this->accountPaymentMethods->of($customer),
            static fn (array $method): bool => isset($ofTheModule[$method['id']]) && $method['usable'],
        ));
    }

    public function cancel(Customer $customer, Subscription $subscription): ?string
    {
        return $this->attempt(fn () => $this->manager->cancel($subscription, ActorType::Customer, (int) $customer->getId()));
    }

    public function pause(Customer $customer, Subscription $subscription): ?string
    {
        return $this->attempt(fn () => $this->manager->pause($subscription, ActorType::Customer, (int) $customer->getId()));
    }

    public function resume(Customer $customer, Subscription $subscription): ?string
    {
        return $this->attempt(fn () => $this->manager->resume($subscription, ActorType::Customer, (int) $customer->getId()));
    }

    public function changePaymentMethod(Customer $customer, Subscription $subscription, int $methodId): ?string
    {
        $method = $this->paymentMethodService->findOwned($customer, $methodId);

        if (null === $method) {
            return self::REFUSED_METHOD;
        }

        return $this->attempt(
            fn () => $this->manager->changePaymentMethod($subscription, $method, ActorType::Customer, (int) $customer->getId()),
            self::REFUSED_METHOD,
        );
    }

    /**
     * @param callable(): mixed $action
     *
     * @return string|null null when done, else the code of the refusal
     */
    private function attempt(callable $action, string $otherRefusal = self::REFUSED_STATE): ?string
    {
        try {
            $action();
        } catch (SubscriptionException) {
            return self::REFUSED_STATE;
        } catch (CustomerPaymentMethodException) {
            return $otherRefusal;
        }

        return null;
    }
}
