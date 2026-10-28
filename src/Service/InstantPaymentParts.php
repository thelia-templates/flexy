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

namespace FlexyBundle\Service;

use FlexyBundle\DTO\PaymentPartsView;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Checkout\DTO\CheckoutDTO;
use Thelia\Domain\Customer\Service\CustomerGroupPaymentPolicy;
use Thelia\Domain\Payment\DTO\PaymentComposition;
use Thelia\Domain\Payment\Exception\PaymentCompositionRefusedException;
use Thelia\Domain\Payment\OrderPaymentComposer;
use Thelia\Log\Tlog;
use Thelia\Model\Cart;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Module\BalanceChargingPaymentModuleInterface;
use Thelia\Module\BaseModule;
use Thelia\Module\InstantPaymentModuleInterface;

/**
 * The payment step's reading of the instant means of the cart.
 *
 * The core keeps no list of them and offers no way to ask for one: a module that settles
 * part of an order inside the shop (InstantPaymentModuleInterface) keeps the buyer's choice
 * on its side, bound to the cart, and only answers how much it applied. So the theme asks
 * the same questions the placement asks, through the core's own composer, and leaves
 * applying a part to the module that owns it.
 *
 * Instance state memoizes the modules, what they said they applied and the compositions
 * for the life of the request, like any other service of the checkout: a live component
 * re-renders on a new request, and ResetInterface makes that hold under a worker runtime.
 */
final class InstantPaymentParts implements ResetInterface
{
    /** @var array<int, array{module: Module, instance: object}>|null */
    private ?array $modules = null;

    /** @var array<string, string> what each module said it applied, by cart and module */
    private array $amounts = [];

    /** @var array<string, PaymentComposition|PaymentCompositionRefusedException> the composition of a cart for a chosen module, by cart and module */
    private array $compositions = [];

    public function __construct(
        private readonly OrderPaymentComposer $composer,
        private readonly ContainerInterface $container,
        private readonly CartFacade $cartFacade,
        private readonly CustomerGroupPaymentPolicy $customerGroupPaymentPolicy,
    ) {
    }

    /**
     * @throws PropelException
     */
    public function viewOf(Cart $cart, ?int $paymentModuleId, string $locale): PaymentPartsView
    {
        $parts = [];
        $active = false;

        foreach ($this->modules() as $moduleId => ['module' => $module, 'instance' => $instance]) {
            // A means the group of the buyer may not pay with is neither listed nor asked: the core
            // leaves it out of the composition and refuses to settle it all the same.
            if (!$instance instanceof InstantPaymentModuleInterface
                || !$this->customerGroupPaymentPolicy->isAllowedForCart($moduleId, $cart)) {
                continue;
            }

            $amount = $this->amountApplied($instance, $cart, $moduleId);
            $active = $active || (float) $amount > 0;
            $parts[] = [
                'moduleId' => $moduleId,
                'title' => '' === $locale ? '' : (string) $module->setLocale($locale)->getTitle(),
                'amount' => $amount,
            ];
        }

        if (!$active) {
            return new PaymentPartsView($parts, false, null, false, null, null);
        }

        try {
            // A module that is no provider: only a composition the instant means cover whole
            // survives it, which is also all the theme needs to know before one is chosen.
            $composition = $this->compositionOf($cart, $paymentModuleId ?? 0);
        } catch (PaymentCompositionRefusedException $refusal) {
            // Nothing chosen to charge the rest is not a module the buyer picked wrong.
            $reason = null === $paymentModuleId
                ? PaymentCompositionRefusedException::REMAINDER_WITHOUT_PROVIDER
                : $refusal->reason();

            return new PaymentPartsView($parts, true, null, false, null, $reason);
        }

        return $this->viewOfComposition($parts, $composition);
    }

    /**
     * Whether the module takes part in a payment as an instant means, not as a provider.
     */
    public function isInstant(int $moduleId): bool
    {
        return ($this->modules()[$moduleId]['instance'] ?? null) instanceof InstantPaymentModuleInterface;
    }

    /**
     * Whether the core lets the module charge only the balance the instant means leave.
     * Without it, it refuses to combine the module with another payment means.
     */
    public function chargesTheBalance(int $moduleId): bool
    {
        return ($this->modules()[$moduleId]['instance'] ?? null) instanceof BalanceChargingPaymentModuleInterface;
    }

    public function reset(): void
    {
        $this->modules = null;
        $this->forget();
    }

    /**
     * Forgets what was read off the modules, for a read that follows a change one of them made.
     */
    public function forget(): void
    {
        $this->amounts = [];
        $this->compositions = [];
    }

    /**
     * The payment module of a cart, as the instant means leave it.
     *
     * The payment step is settled once the cart names a payment module, and the core makes
     * the last instant means the module of such an order: the buyer is not asked to pick a
     * provider they will not be charged by, so this one is written for them (whatever module
     * the cart named before, and without the stored method it carried). When the instant
     * means no longer cover the order, a cart whose module is one of them is left without
     * module: it would otherwise stay "chosen" for an order the module does not pay whole.
     * A provider the buyer chose is left alone while the instant means do not cover.
     *
     * @return bool whether the cart changed
     *
     * @throws PropelException
     */
    public function settleThePaymentModuleOfTheCart(Cart $cart): bool
    {
        $view = $this->viewOf($cart, null, '');
        $cartModuleId = $cart->getPaymentModuleId();

        if ($view->coversTheOrder && null !== $view->primaryModuleId) {
            if ($cartModuleId === $view->primaryModuleId && null === $cart->getCustomerPaymentMethodId()) {
                return false;
            }

            $this->cartFacade->setPaymentModule(new CheckoutDTO(cart: $cart, paymentModuleId: $view->primaryModuleId));

            return true;
        }

        if (null === $cartModuleId || !$this->isInstant($cartModuleId)) {
            return false;
        }

        // The core's entry casts a missing module to 0: the model is where null is written.
        $cart->setPaymentModuleId(null)->setCustomerPaymentMethodId(null)->save();

        return true;
    }

    /**
     * @param list<array{moduleId: int, title: string, amount: string}> $parts
     */
    private function viewOfComposition(array $parts, PaymentComposition $composition): PaymentPartsView
    {
        $planned = [];
        $primary = null;

        foreach ($composition->parts as $part) {
            if (!$part->isInstant) {
                continue;
            }

            $planned[$part->moduleId] = $part->amount;

            if ($part->isPrimary) {
                $primary = $part->moduleId;
            }
        }

        // The amount the core would write, not the one the module offered: a part never
        // exceeds what is still due.
        foreach ($parts as $position => $part) {
            $parts[$position]['amount'] = $planned[$part['moduleId']] ?? '0';
        }

        $covers = 0.0 === (float) $composition->balance;

        return new PaymentPartsView($parts, true, $composition->balance, $covers, $covers ? $primary : null, null);
    }

    /**
     * A module that cannot say what it applied applies nothing, as it does for the core.
     * The answer is read the way the core's composer reads it: a plain decimal is kept as
     * it is, another number is written as one ("1.0E-5" is not an amount), and what is no
     * number counts as nothing.
     */
    private function amountApplied(InstantPaymentModuleInterface $instance, Cart $cart, int $moduleId): string
    {
        // A cart with no id has no key of its own: every one of them would share ":module".
        if (null === $cart->getId()) {
            return $this->readAmountApplied($instance, $cart, $moduleId);
        }

        $key = $cart->getId().':'.$moduleId;

        return $this->amounts[$key] ??= $this->readAmountApplied($instance, $cart, $moduleId);
    }

    private function readAmountApplied(InstantPaymentModuleInterface $instance, Cart $cart, int $moduleId): string
    {
        try {
            $amount = trim($instance->getAmountAppliedToCart($cart));
        } catch (\Throwable $throwable) {
            Tlog::getInstance()->error(\sprintf('Payment module %d could not tell the amount it applied to the cart (%s): the payment step shows none.', $moduleId, $throwable::class));

            return '0';
        }

        if (!is_numeric($amount) || !is_finite((float) $amount) || (float) $amount <= 0) {
            return '0';
        }

        return 1 === preg_match('/^\d+(?:\.\d+)?$/', $amount) ? $amount : number_format((float) $amount, 6, '.', '');
    }

    /**
     * @throws PaymentCompositionRefusedException
     * @throws PropelException
     */
    private function compositionOf(Cart $cart, int $paymentModuleId): PaymentComposition
    {
        // Kept by cart and module only: the total is not part of the key, so whoever changes it forgets.
        $key = null === $cart->getId() ? null : $cart->getId().':'.$paymentModuleId;

        if (null === $key || !isset($this->compositions[$key])) {
            try {
                $composition = $this->composer->composeForCart($cart, $paymentModuleId);
            } catch (PaymentCompositionRefusedException $refusal) {
                $composition = $refusal;
            }

            if (null === $key) {
                if ($composition instanceof PaymentCompositionRefusedException) {
                    throw $composition;
                }

                return $composition;
            }

            $this->compositions[$key] = $composition;
        }

        $composition = $this->compositions[$key];

        if ($composition instanceof PaymentCompositionRefusedException) {
            throw $composition;
        }

        return $composition;
    }

    /**
     * @return array<int, array{module: Module, instance: object}>
     */
    private function modules(): array
    {
        if (null !== $this->modules) {
            return $this->modules;
        }

        $this->modules = [];

        $rows = ModuleQuery::create()
            ->filterByActivate(BaseModule::IS_ACTIVATED)
            ->filterByType(BaseModule::PAYMENT_MODULE_TYPE)
            ->orderByPosition()
            ->find();

        foreach ($rows as $module) {
            try {
                $this->modules[(int) $module->getId()] = [
                    'module' => $module,
                    'instance' => $module->getPaymentModuleInstance($this->container),
                ];
            } catch (\InvalidArgumentException) {
                // A module row whose code is gone is no payment means any more.
                continue;
            }
        }

        return $this->modules;
    }
}
