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

namespace FlexyBundle\Components\Organisms\Payment;

use FlexyBundle\DTO\PaymentPartsView;
use FlexyBundle\Event\CheckoutEvents;
use FlexyBundle\Service\InstantPaymentParts;
use FlexyBundle\Service\PaymentOptionChoice;
use FlexyBundle\Service\PaymentOptionChoiceStore;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Core\Content\Slot\ContentSlots;
use Thelia\Core\Content\Slot\ContentSlotService;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Checkout\DTO\CheckoutDTO;
use Thelia\Domain\Checkout\Service\ConsentAcceptanceStore;
use Thelia\Domain\Checkout\Service\ConsentProvider;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Payment\Exception\CustomerPaymentMethodException;

#[AsLiveComponent]
class Base
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    /**
     * The tag this step's title is written with.
     *
     * A page that stacks the whole tunnel carries three of these organisms at once, and
     * three h1 tell a screen reader it is looking at three documents: that page hands
     * over 'h2'. A checkout of one screen per step keeps the h1 it has always had.
     */
    #[LiveProp]
    public string $headingLevel = 'h1';

    /** The code of the option group in which the core offers the buyer's stored payment methods. */
    private const STORED_METHODS_GROUP = 'stored_methods';

    #[LiveProp]
    public ?int $paymentModuleId = null;

    #[LiveProp]
    public ?int $invoiceAddressId = null;

    /**
     * What the buyer has answered so far, by consent code.
     *
     * Mirrors the session store rather than replacing it: the store is what decides
     * whether the order goes through, and it is the one OrderFacade freezes on the
     * order. This is the display state, refilled from the store on mount and after
     * every answer, the way paymentModuleId is refilled from the cart.
     *
     * @var array<string, bool>
     */
    #[LiveProp]
    public array $consentAcceptances = [];

    /**
     * The options of the chosen payment module the buyer has picked, as the session
     * holds them: refilled from the store on mount and after every answer.
     *
     * @var list<array{group: string, values: list<string>}>
     */
    #[LiveProp]
    public array $paymentOptionChoices = [];

    /**
     * Why the last choice of the buyer was not kept, as a code the template turns into a
     * sentence. Not live state: it is read by the render that follows the choice and is
     * gone with the next one.
     */
    public ?string $notice = null;

    /** @var array<array-key, array<string, mixed>>|null */
    private ?array $modules = null;

    private ?PaymentPartsView $partsView = null;

    public function __construct(
        private readonly DataAccessService $dataAccessService,
        private readonly CartFacade $cartFacade,
        private readonly ConsentProvider $consentProvider,
        private readonly ConsentAcceptanceStore $consentAcceptanceStore,
        private readonly LangService $langService,
        private readonly ContentSlotService $contentSlotService,
        private readonly PaymentOptionChoiceStore $paymentOptionChoiceStore,
        private readonly InstantPaymentParts $instantPaymentParts,
    ) {
    }

    public function mount(): void
    {
        // Before anything is read off the cart: a payment the instant means make whole has
        // no provider for the buyer to pick, and the step is not settled without a module.
        $this->settleTheModuleOfTheParts();
        $this->refillFromTheCart();
        $this->consentAcceptances = $this->consentAcceptanceStore->all();
        $this->paymentOptionChoices = $this->paymentOptionChoiceStore->all();
    }

    public function getModules(): array
    {
        return $this->modules ??= $this->dataAccessService->resources('/api/front/payment/modules') ?? [];
    }

    /**
     * The stored payment method written on the cart, null when the buyer pays with a new
     * one. Read off the cart at each render and kept nowhere else: the cart is what the
     * core pays the order with, and a live property would come back from the browser as
     * the buyer last saw it.
     */
    public function getStoredMethodId(): ?int
    {
        return $this->cartFacade->getOrCreateFromSession()->getCustomerPaymentMethodId();
    }

    /**
     * What the instant means of the cart pay of it, and what is left.
     */
    public function getParts(): PaymentPartsView
    {
        return $this->partsView ??= $this->instantPaymentParts->viewOf(
            $this->cartFacade->getOrCreateFromSession(),
            $this->cartFacade->getPaymentModuleId(),
            (string) $this->langService->getLocale(),
        );
    }

    /**
     * The payment methods the buyer picks the provider of the payment from.
     *
     * An instant means is not one of them: it is applied in the block of the parts, by the
     * module itself. Nothing is offered when the parts pay the order whole, and once they
     * pay a part of it only the modules the core lets charge the balance are: the others
     * would be refused at the placement.
     *
     * @return list<array<string, mixed>>
     */
    public function getProviders(): array
    {
        $parts = $this->getParts();

        if ($parts->coversTheOrder) {
            return [];
        }

        $providers = [];

        foreach ($this->getModules() as $module) {
            $moduleId = (int) ($module['id'] ?? 0);

            if (!($module['valid'] ?? false) || $this->instantPaymentParts->isInstant($moduleId)) {
                continue;
            }

            if ($parts->active && !$this->instantPaymentParts->chargesTheBalance($moduleId)) {
                continue;
            }

            $providers[] = $module;
        }

        return $providers;
    }

    /**
     * Whether an instant means pays a part of the order and no provider is able to charge
     * what is left: the buyer has nothing to choose, and is told why.
     */
    public function getNoProviderCanChargeTheBalance(): bool
    {
        $parts = $this->getParts();

        return $parts->active && !$parts->coversTheOrder && [] === $this->getProviders();
    }

    /**
     * The tag of the block of the parts: the step's own level, one down.
     */
    public function getPartsHeadingLevel(): string
    {
        return 'h'.min(6, 1 + (int) substr($this->headingLevel, 1));
    }

    /**
     * Read again by the render that follows: what the module that owns a part just changed
     * is read off the module, not off what this component showed before.
     */
    #[LiveListener(CheckoutEvents::PAYMENT_PARTS_CHANGED)]
    public function readThePartsAgain(): void
    {
        $this->instantPaymentParts->forget();
        $this->partsView = null;
        $this->settleTheModuleOfTheParts();
        $this->refillFromTheCart();

        $this->emit('updateNextButton');
    }

    /**
     * Writes the stored payment method the buyer picked on the cart, or none for a new one.
     *
     * Through the core, which judges it: the method must be the buyer's, stored through the
     * module chosen and not expired. A refusal writes nothing and is shown as a notice; the
     * theme keeps no copy of the choice.
     */
    #[LiveAction]
    public function chooseStoredMethod(#[LiveArg] int $customerPaymentMethodId = 0): void
    {
        $this->notice = null;

        // The module the cart names now, not the one the browser last saw.
        $paymentModuleId = $this->cartFacade->getPaymentModuleId();

        if (null !== $paymentModuleId) {
            try {
                $this->cartFacade->setPaymentModule(new CheckoutDTO(
                    cart: $this->cartFacade->getOrCreateFromSession(),
                    paymentModuleId: $paymentModuleId,
                    customerPaymentMethodId: $customerPaymentMethodId > 0 ? $customerPaymentMethodId : null,
                ));
            } catch (CustomerPaymentMethodException) {
                $this->notice = 'stored_method_refused';
            }
        }

        $this->refillFromTheCart();
        $this->emit('updateNextButton');
    }

    /**
     * The stored methods a module offers for this cart, from its option group.
     *
     * @param array<string, mixed> $module
     *
     * @return list<array<string, mixed>>
     */
    private function storedMethodsOf(array $module): array
    {
        foreach ($module['optionGroups'] ?? [] as $group) {
            if (self::STORED_METHODS_GROUP === ($group['code'] ?? null)) {
                return array_values(array_filter(
                    $group['options'] ?? [],
                    static fn (array $option): bool => null !== ($option['customerPaymentMethodId'] ?? null),
                ));
            }
        }

        return [];
    }

    /**
     * The method to pay with when a module is chosen: the one the buyer made their default,
     * else the first the module offers, which the core lists default first. None when the
     * module offers none.
     *
     * @param array<string, mixed> $module
     */
    private function storedMethodToPreselect(array $module): ?int
    {
        $methods = $this->storedMethodsOf($module);

        foreach ($methods as $method) {
            if ($method['default'] ?? false) {
                return (int) $method['customerPaymentMethodId'];
            }
        }

        return [] === $methods ? null : (int) $methods[0]['customerPaymentMethodId'];
    }

    /**
     * Lets the parts name the payment module of the cart, which may change it: an instant
     * means that now covers the order takes the place of the provider, and one that no
     * longer does gives it up. The options picked belong to the module they were picked
     * for, so they do not follow the change.
     *
     * The module is read off the cart before and after, not off paymentModuleId: on mount
     * that property is still empty, and on a live call it holds what the browser last saw.
     */
    private function settleTheModuleOfTheParts(): void
    {
        $previousModuleId = $this->cartFacade->getPaymentModuleId();

        $this->instantPaymentParts->settleThePaymentModuleOfTheCart($this->cartFacade->getOrCreateFromSession());
        $this->partsView = null;

        if ($previousModuleId !== $this->cartFacade->getPaymentModuleId()) {
            $this->forgetThePaymentOptionChoices();
        }
    }

    /**
     * The options picked belong to the module they were picked for: when the module changes,
     * the session and the display state both let them go.
     */
    private function forgetThePaymentOptionChoices(): void
    {
        $this->paymentOptionChoiceStore->replace([]);
        $this->paymentOptionChoices = [];
    }

    private function refillFromTheCart(): void
    {
        $this->invoiceAddressId = $this->cartFacade->getInvoiceAddressId();
        $this->paymentModuleId = $this->cartFacade->getPaymentModuleId();
    }

    /**
     * Records one option of the chosen payment module, picked or let go.
     *
     * The answer is passed rather than flipped, as for the consents, so that a change
     * event fired twice writes it twice instead of undoing it. Only an option the chosen
     * module offers for this cart is kept: see PaymentOptionChoice.
     */
    #[LiveAction]
    public function choosePaymentOption(#[LiveArg] string $group, #[LiveArg] string $option, #[LiveArg] bool $chosen): void
    {
        $this->paymentOptionChoiceStore->replace(PaymentOptionChoice::answer(
            $this->offeredOptionGroups(),
            $this->paymentOptionChoiceStore->all(),
            $group,
            $option,
            $chosen,
        ));

        $this->paymentOptionChoices = $this->paymentOptionChoiceStore->all();
    }

    /**
     * @return array<array-key, array<string, mixed>>
     */
    private function offeredOptionGroups(): array
    {
        foreach ($this->getModules() as $module) {
            if (($module['valid'] ?? false) && (int) $module['id'] === $this->paymentModuleId) {
                // The stored methods are written on the cart by the core, never kept here:
                // see chooseStoredMethod().
                return array_values(array_filter(
                    $module['optionGroups'] ?? [],
                    static fn (array $group): bool => self::STORED_METHODS_GROUP !== ($group['code'] ?? null),
                ));
            }
        }

        return [];
    }

    /**
     * The boxes to show, in the order the merchant put them in.
     *
     * The wording comes out as plain text and is printed escaped: a consent is a
     * sentence the buyer agrees to, not a piece of markup a shop administrator can
     * inject into the payment page. The link to the full text is built here instead,
     * from the content the consent points at, and rendered next to the box rather than
     * inside its label — a link nested in a label toggles the box when clicked. That
     * content is read through the consent's content slot, so a hidden content gives no
     * link rather than one to a page the buyer cannot open.
     *
     * @return list<array{code: string, title: string, description: string, url: string|null, mandatory: bool, accepted: bool}>
     */
    public function getConsents(): array
    {
        $locale = (string) $this->langService->getLocale();
        $consents = [];

        foreach ($this->consentProvider->activeConsents() as $consent) {
            $code = (string) $consent->getCode();

            $consents[] = [
                'code' => $code,
                'title' => $this->consentProvider->title($consent, $locale),
                'description' => $this->consentProvider->description($consent, $locale),
                'url' => $this->contentSlotService->first(ContentSlots::CONSENT_PREFIX.$code, $locale)?->url,
                'mandatory' => $consent->isMandatory(),
                'accepted' => $this->consentAcceptances[$code] ?? false,
            ];
        }

        return $consents;
    }

    /**
     * Records one answer, and tells the next button to look again.
     *
     * The value to store is passed rather than flipped: the template computes it from
     * the state the server just rendered, so the same call arriving twice — a change
     * event fired by both the box and the label around it — writes the same answer
     * twice instead of undoing itself.
     *
     * A code the shop is not asking for right now is ignored: the answers written back
     * are those of the active consents and nothing else.
     *
     * Each answer goes down with the wording and the long text that were on screen when
     * it was given, because that is what the order will carry as the proof. The store
     * keeps the ones it already holds for the boxes this call does not move.
     */
    #[LiveAction]
    public function toggleConsent(#[LiveArg] string $code, #[LiveArg] bool $accepted): void
    {
        $locale = (string) $this->langService->getLocale();
        $answers = [];

        foreach ($this->consentProvider->activeConsents() as $consent) {
            $consentCode = (string) $consent->getCode();

            $answers[$consentCode] = [
                'accepted' => $consentCode === $code
                    ? $accepted
                    : $this->consentAcceptanceStore->isAccepted($consentCode),
                'title' => $this->consentProvider->title($consent, $locale),
                'description' => $this->consentProvider->description($consent, $locale),
            ];
        }

        $this->consentAcceptanceStore->replace($answers);

        $this->consentAcceptances = $this->consentAcceptanceStore->all();

        $this->emit('updateNextButton');
    }

    #[LiveListener(CheckoutEvents::SET_PAYMENT_MODULE_ID)]
    public function selectPaymentModuleId(#[LiveArg] int $moduleId): void
    {
        $this->notice = null;
        $cart = $this->cartFacade->getOrCreateFromSession();

        // A click on the card already chosen keeps the method picked in it: only a module
        // that was not chosen yet gets its default one written.
        $preselected = $moduleId === $cart->getPaymentModuleId()
            ? $cart->getCustomerPaymentMethodId()
            : $this->storedMethodToPreselect($this->moduleById($moduleId));

        try {
            $this->cartFacade->setPaymentModule(new CheckoutDTO(
                cart: $cart,
                paymentModuleId: $moduleId,
                customerPaymentMethodId: $preselected,
            ));
        } catch (CustomerPaymentMethodException) {
            // The method listed a moment ago cannot be paid with any more (it expired, or
            // was forgotten from the account): the module is chosen all the same, and the
            // buyer pays with a new method until they pick another.
            $this->cartFacade->setPaymentModule(new CheckoutDTO(
                cart: $cart,
                paymentModuleId: $moduleId,
            ));
            $this->notice = 'stored_method_refused';
        }

        $previousModuleId = $this->paymentModuleId;
        $this->partsView = null;
        $this->refillFromTheCart();

        if ($previousModuleId !== $this->paymentModuleId) {
            $this->forgetThePaymentOptionChoices();
        }

        $this->emit('updateNextButton');
    }

    /**
     * @return array<string, mixed>
     */
    private function moduleById(int $moduleId): array
    {
        foreach ($this->getModules() as $module) {
            if ((int) ($module['id'] ?? 0) === $moduleId && ($module['valid'] ?? false)) {
                return $module;
            }
        }

        return [];
    }
}
