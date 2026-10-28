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

namespace FlexyBundle\Components\Organisms\Delivery;

use FlexyBundle\Event\CheckoutEvents;
use FlexyBundle\Service\DeliveryAddressCopy;
use FlexyBundle\Service\DeliveryDateBridge;
use FlexyBundle\Service\DeliveryOptionChoice;
use FlexyBundle\Service\GuestCheckoutGate;
use FlexyBundle\Service\PriceDisplay;
use Propel\Runtime\Exception\PropelException;
use Psr\Log\LoggerInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Api\Resource\DeliveryModuleOption;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Addressing\Exception\AddressNotFoundException;
use Thelia\Domain\Addressing\Service\AddressService;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Checkout\DTO\CheckoutDTO;
use Thelia\Domain\Checkout\Exception\DestinationRestrictedException;
use Thelia\Domain\Customer\Exception\CustomerException;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Shipping\ShippingFacade;

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

    #[LiveProp]
    public ?int $deliveryModuleId = null;

    #[LiveProp]
    public ?string $deliveryModuleOptionCode = null;

    #[LiveProp]
    public ?int $deliveryAddressId = null;

    #[LiveProp]
    public ?int $invoiceAddressId = null;

    #[LiveProp]
    public bool $showNewAddressForm = false;

    #[LiveProp]
    public ?int $editingAddressId = null;

    /**
     * Said once, after an address change took away the delivery day the buyer had picked:
     * `date_gone` when the carrier stays and the day is no longer offered, `carrier_gone`
     * when the carrier no longer serves the address. Worded by the template, which reads the
     * catalogues of the theme.
     */
    #[LiveProp]
    public ?string $deliveryDateNotice = null;

    /**
     * The products of the cart that cannot ship to the address the buyer just picked (or to the
     * default one the step selected on its own), each as the core labels it: title and reason.
     * Data only: the template words and translates the warning. The address is refused before it
     * is written, so the cart keeps the one it had.
     *
     * @var list<string>
     */
    #[LiveProp]
    public array $restrictedProducts = [];

    public function __construct(
        private readonly Session $session,
        private readonly ShippingFacade $shippingFacade,
        private readonly CartFacade $cartFacade,
        private readonly AddressService $addressService,
        private readonly LangService $langService,
        private readonly LoggerInterface $logger,
        private readonly GuestCheckoutGate $guestCheckoutGate,
        private readonly DeliveryDateBridge $deliveryDates,
        private readonly DeliveryAddressCopy $deliveryAddressCopy,
        private readonly PriceDisplay $priceDisplay,
    ) {
    }

    public function mount(): void
    {
        $this->deliveryAddressId = $this->cartFacade->getDeliveryAddressId();

        if (null !== $this->deliveryAddressId) {
            // Edited since it was chosen, from the account or another step: the cart follows.
            $this->followTheDeliveryAddress($this->deliveryAddressId);
        } else {
            $defaultAddresses = array_filter(
                $this->getAddressList(),
                static fn (array $address): bool => (bool) $address['isDefault'],
            );

            $defaultAddress = reset($defaultAddresses);

            if (false !== $defaultAddress) {
                $this->selectDeliveryAddress($defaultAddress['id']);
            }
        }

        $this->invoiceAddressId = $this->cartFacade->getInvoiceAddressId();
        $this->deliveryModuleId = $this->cartFacade->getDeliveryModuleId();
    }

    #[LiveListener('cancelAddressForm')]
    public function resetAddressForm(): void
    {
        $this->showNewAddressForm = false;
        $this->editingAddressId = null;
    }

    #[LiveListener(CheckoutEvents::ADD_NEW_DELIVERY_ADDRESS)]
    public function addressSaved(): void
    {
        $edited = $this->editingAddressId;
        $this->resetAddressForm();

        if (null !== $edited && $edited === $this->cartFacade->getDeliveryAddressId()) {
            $this->followTheDeliveryAddress($edited);
        }
    }

    /**
     * An edit of the delivery address reaches the cart, on this step or once back on it: a
     * new place is chosen again, which asks the carriers and their postage again.
     */
    private function followTheDeliveryAddress(int $addressId): void
    {
        if ($this->deliveryAddressCopy->followUnlessMoved($addressId)) {
            $this->selectDeliveryAddress($addressId);
        }
    }

    #[LiveListener(CheckoutEvents::EDIT_DELIVERY_ADDRESS)]
    public function setEditingAddress(#[LiveArg] int $addressId): void
    {
        $this->guestCheckoutGate->assertVisible($addressId);

        $this->editingAddressId = $addressId;
    }

    #[LiveListener(CheckoutEvents::DELETE_DELIVERY_ADDRESS)]
    public function deleteAddress(#[LiveArg] int $addressId): void
    {
        // Ahead of the try: the deletion below turns a refusal into a logged line and a
        // silent no-op, which is the right answer to a race and the wrong one to an
        // address this checkout was never given.
        $this->guestCheckoutGate->assertVisible($addressId);

        try {
            $this->addressService->deleteAddress($addressId);
        } catch (PropelException|AddressNotFoundException|CustomerException $e) {
            $this->logger->error(\sprintf('Error during address deletion: %s', $e->getMessage()));
        }
    }

    #[LiveAction]
    public function toggleNewAddressForm(): void
    {
        $this->showNewAddressForm = !$this->showNewAddressForm;
    }

    /**
     * The address book of whoever is checking out — never the whole of the row's own.
     *
     * A guest shares their customer row with everyone who ever ordered on that address,
     * so the gate narrows the list down to the addresses of this identification. A
     * signed-in customer gets theirs, whole.
     */
    public function getAddressList(): array
    {
        return $this->guestCheckoutGate->visibleAddresses();
    }

    public function getDeliveryModulesOptions(): array
    {
        $cart = $this->cartFacade->getOrCreateFromSession();
        $deliveryModulesWithOption = $this->shippingFacade->listValidMethods($cart);
        $locale = $this->langService->getLocale();
        $deliveryOptions = [];
        $display = $this->priceDisplay->forVisitor();

        foreach ($deliveryModulesWithOption as $deliveryModuleWithOptionDTO) {
            $options = $deliveryModuleWithOptionDTO->getDeliveryModuleOption();
            $module = $deliveryModuleWithOptionDTO->getDeliveryModule();
            $i18ns = $module->getI18ns()->i18ns;
            $i18n = $i18ns[$locale] ?? $i18ns['en_US'] ?? null;

            if (null === $i18n && [] !== $i18ns) {
                $i18n = $i18ns[array_key_first($i18ns)];
            }

            // A module with no translation row at all must not take the whole step down with
            // it: reset() returned false here, and getTitle() on false is fatal for every
            // module on the page. Falling back to the code keeps the option selectable —
            // losing the only delivery method would block the order outright.
            $title = $i18n?->getTitle() ?? $module->getCode();

            foreach ($options as $option) {
                $code = $option->getCode();
                $deliveryOptions[$code] = [
                    'code' => $code,
                    'title' => $title,
                    'moduleId' => $module->getId(),
                    'deliveryMode' => $module->getDeliveryMode(),
                    'postage' => $option->getPostage(),
                    // What the buyer reads: the postage the carrier quoted, with or without its taxes.
                    'price' => $this->priceDisplay->ofDeliveryOption($display, $option)?->amount,
                    // What the module tells the buyer about the option: the pickup hours of a
                    // store, the delay of a carrier. Plain text, escaped where it is printed.
                    'description' => self::descriptionOf($option),
                    // What the buyer picks with this carrier: none, a day, or a slot. A core
                    // that predates delivery dates answers nothing, which is none.
                    'deliveryDateChoice' => method_exists($module, 'getDeliveryDateChoice') ? $module->getDeliveryDateChoice() : 'none',
                ];
            }
        }

        return $deliveryOptions;
    }

    /**
     * The titles of the products that leave the cart with no carrier at all, to be named in
     * place of the generic "no delivery method" sentence. Data only: the template words and
     * translates the sentence, as a translation made here would not see the catalogues of
     * the theme. Empty while a carrier is left, and with a core that predates the shipping
     * rules of a product.
     *
     * @return list<string>
     */
    public function getProductsBlockingEveryCarrier(): array
    {
        if (!method_exists(ShippingFacade::class, 'productsBlockingEveryCarrier')) {
            return [];
        }

        $cart = $this->cartFacade->getOrCreateFromSession();
        $country = $this->shippingFacade->getCountryForCart($cart);

        if (null === $country) {
            return [];
        }

        $state = $cart->getCartAddressRelatedByAddressDeliveryId()?->getState();

        return array_values($this->shippingFacade->productsBlockingEveryCarrier($cart, $country, $state, $this->langService->getLocale()));
    }

    /**
     * The core option declares its description without a default, so a module that never
     * sets one leaves a property PHP refuses to read: that option gets no description
     * rather than taking the delivery step down.
     */
    private static function descriptionOf(DeliveryModuleOption $option): string
    {
        try {
            return $option->getDescription();
        } catch (\Error) {
            return '';
        }
    }

    /**
     * The postal code of the delivery address when it is why no carrier is offered, so that
     * the step says so instead of only showing an empty list. Null on a core that does not
     * know postal code rules yet.
     */
    public function getPostalCodeWithoutCarrier(): ?string
    {
        if (!method_exists($this->shippingFacade, 'postalCodeWithoutCarrier')) {
            return null;
        }

        return $this->shippingFacade->postalCodeWithoutCarrier($this->cartFacade->getOrCreateFromSession());
    }

    #[LiveListener(CheckoutEvents::SET_DELIVERY_MODULE_OPTION)]
    public function selectDeliveryModuleOption(#[LiveArg] string $optionCode, #[LiveArg] int $moduleId): void
    {
        // The option and the module come from the page: only one the cart can be shipped
        // with is written to it.
        if (!DeliveryOptionChoice::isOffered($this->getDeliveryModulesOptions(), $optionCode, $moduleId)) {
            $this->logger->warning(\sprintf('Delivery option %s of module #%d is not offered for this cart', $optionCode, $moduleId));

            return;
        }

        $this->cartFacade->setDeliveryAddress(new CheckoutDTO($this->cartFacade->getOrCreateFromSession()));

        $this->invoiceAddressId = null;

        $this->cartFacade->setDeliveryModule(new CheckoutDTO(
            cart: $this->cartFacade->getOrCreateFromSession(),
            deliveryModuleId: $moduleId,
        ));

        $this->session->set('deliveryModuleOption', $optionCode);
        $this->deliveryDateNotice = null;

        $this->deliveryModuleId = $moduleId;
        $this->deliveryModuleOptionCode = $optionCode;

        if (strtolower($optionCode) === 'localpickup') {
            $this->shippingFacade->setCustomerDefaultDeliveryAddress($this->cartFacade->getOrCreateFromSession());
        }

        $this->emit(CheckoutEvents::DELIVERY_MODULE_OPTION_SAVED);
        $this->emit('syncSummary');
        $this->emit('updateNextButton');
    }

    #[LiveListener(CheckoutEvents::SET_DELIVERY_ORDER_ADDRESS_ID)]
    public function selectDeliveryAddress(#[LiveArg] int $addressId): void
    {
        $this->guestCheckoutGate->assertVisible($addressId);

        $cart = $this->cartFacade->getOrCreateFromSession();
        $carrierOfTheDay = null === $this->deliveryDates->chosenOn($cart, null) ? null : $cart->getDeliveryModuleId();

        try {
            $this->cartFacade->setDeliveryAddress(new CheckoutDTO(
                cart: $cart,
                deliveryAddressId: $addressId,
            ));
        } catch (DestinationRestrictedException $exception) {
            // The buyer's business, not a server error: nothing was written, the step names what to remove.
            $this->restrictedProducts = array_map(static fn ($line): string => $line->label(), $exception->lines);
            $this->deliveryAddressId = $this->cartFacade->getDeliveryAddressId();
            $this->emit('updateNextButton');

            return;
        }

        $this->restrictedProducts = [];
        $this->deliveryAddressId = $this->cartFacade->getDeliveryAddressId();
        $this->deliveryDateNotice = null;

        if (null !== $carrierOfTheDay && $this->offersModule($carrierOfTheDay)) {
            // The buyer picked a day: it stays as long as the carrier serves the new address
            // and the day is still offered. Choosing the carrier again requotes its postage
            // for that address, and takes it off if it cannot quote.
            $this->cartFacade->setDeliveryModule(new CheckoutDTO(cart: $cart, deliveryModuleId: $carrierOfTheDay));
            $this->deliveryModuleId = $cart->getDeliveryModuleId();

            if (null === $this->deliveryModuleId) {
                // The carrier could not quote the new address after all: it went, and the day with it.
                $this->deliveryDateNotice = 'carrier_gone';
            } elseif ($this->deliveryDates->dropIfNoLongerPossible($cart)) {
                $this->deliveryDateNotice = 'date_gone';
            }
        } else {
            // The carrier goes, and the postage it was priced with: the core prices the carrier the cart still had for the
            // new address, and the summary reads that column, so a carrier nobody chose would be charged.
            $cart
                ->setDeliveryModuleId(null)
                ->setPostage(null)
                ->setPostageTax('0')
                ->setPostageTaxRuleTitle(null)
                ->save();
            $this->deliveryModuleId = null;

            if (null !== $carrierOfTheDay) {
                $this->deliveryDateNotice = 'carrier_gone';
            }
        }

        $this->emit('syncSummary');
        $this->emit('updateNextButton');
    }

    private function offersModule(int $moduleId): bool
    {
        foreach ($this->getDeliveryModulesOptions() as $option) {
            if ($option['moduleId'] === $moduleId) {
                return true;
            }
        }

        return false;
    }

    #[LiveListener(CheckoutEvents::DELIVERY_DATE_CHOSEN)]
    public function forgetTheDeliveryDateNotice(): void
    {
        $this->deliveryDateNotice = null;
    }

    #[LiveListener(CheckoutEvents::SET_INVOICE_ORDER_ADDRESS_ID)]
    public function selectInvoiceAddress(#[LiveArg] ?int $addressId): void
    {
        // Null is "bill me where you ship me", and names no address to check.
        if (null !== $addressId) {
            $this->guestCheckoutGate->assertVisible($addressId);
        }

        $this->cartFacade->setInvoiceAddress(new CheckoutDTO(
            cart: $this->cartFacade->getOrCreateFromSession(),
            invoiceAddressId: $addressId,
        ));
        $this->invoiceAddressId = $this->cartFacade->getInvoiceAddressId();

        $this->emit('syncSummary');
    }
}
