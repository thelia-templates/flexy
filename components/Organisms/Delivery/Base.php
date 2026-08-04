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
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Map\TableMap;
use Psr\Log\LoggerInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Addressing\Exception\AddressNotFoundException;
use Thelia\Domain\Addressing\Service\AddressService;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Checkout\DTO\CheckoutDTO;
use Thelia\Domain\Customer\Exception\CustomerException;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Shipping\ShippingFacade;
use Thelia\Model\Customer;

#[AsLiveComponent]
class Base
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

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

    public function __construct(
        private readonly Session $session,
        private readonly ShippingFacade $shippingFacade,
        private readonly CartFacade $cartFacade,
        private readonly AddressService $addressService,
        private readonly LangService $langService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function mount(): void
    {
        $this->deliveryAddressId = $this->cartFacade->getDeliveryAddressId();

        if (null === $this->deliveryAddressId) {
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

    #[LiveListener(CheckoutEvents::ADD_NEW_DELIVERY_ADDRESS)]
    #[LiveListener('cancelAddressForm')]
    public function resetAddressForm(): void
    {
        $this->showNewAddressForm = false;
        $this->editingAddressId = null;
    }

    #[LiveListener(CheckoutEvents::EDIT_DELIVERY_ADDRESS)]
    public function setEditingAddress(#[LiveArg] int $addressId): void
    {
        $this->editingAddressId = $addressId;
    }

    #[LiveListener(CheckoutEvents::DELETE_DELIVERY_ADDRESS)]
    public function deleteAddress(#[LiveArg] int $addressId): void
    {
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

    public function getAddressList(): array
    {
        /** @var Customer|null $user */
        $user = $this->session->getCustomerUser();

        return $user?->getAddresses()->toArray(null, false, TableMap::TYPE_CAMELNAME) ?? [];
    }

    public function getDeliveryModulesOptions(): array
    {
        $cart = $this->cartFacade->getOrCreateFromSession();
        $deliveryModulesWithOption = $this->shippingFacade->listValidMethods($cart);
        $locale = $this->langService->getLocale();
        $deliveryOptions = [];

        foreach ($deliveryModulesWithOption as $deliveryModuleWithOptionDTO) {
            $options = $deliveryModuleWithOptionDTO->getDeliveryModuleOption();
            $module = $deliveryModuleWithOptionDTO->getDeliveryModule();
            $i18ns = $module->getI18ns()->i18ns;
            $title = ($i18ns[$locale] ?? $i18ns['en_US'] ?? reset($i18ns))->getTitle();

            foreach ($options as $option) {
                $code = $option->getCode();
                $deliveryOptions[$code] = [
                    'code' => $code,
                    'title' => $title,
                    'moduleId' => $module->getId(),
                    'deliveryMode' => $module->getDeliveryMode(),
                    'postage' => $option->getPostage(),
                ];
            }
        }

        return $deliveryOptions;
    }

    #[LiveListener(CheckoutEvents::SET_DELIVERY_MODULE_OPTION)]
    public function selectDeliveryModuleOption(#[LiveArg] string $optionCode, #[LiveArg] int $moduleId): void
    {
        $this->cartFacade->setDeliveryAddress(new CheckoutDTO($this->cartFacade->getOrCreateFromSession()));

        $this->invoiceAddressId = null;

        $this->cartFacade->setDeliveryModule(new CheckoutDTO(
            cart: $this->cartFacade->getOrCreateFromSession(),
            deliveryModuleId: $moduleId,
        ));

        $this->session->set('deliveryModuleOption', $optionCode);

        $this->deliveryModuleId = $moduleId;
        $this->deliveryModuleOptionCode = $optionCode;

        if (strtolower($optionCode) === 'localpickup') {
            $this->shippingFacade->setCustomerDefaultDeliveryAddress($this->cartFacade->getOrCreateFromSession());
        }

        $this->emit('syncSummary');
        $this->emit('updateNextButton');
    }

    #[LiveListener(CheckoutEvents::SET_DELIVERY_ORDER_ADDRESS_ID)]
    public function selectDeliveryAddress(#[LiveArg] int $addressId): void
    {
        $cart = $this->cartFacade->getOrCreateFromSession();
        $this->cartFacade->setDeliveryAddress(new CheckoutDTO(
            cart: $cart,
            deliveryAddressId: $addressId,
        ));
        $this->deliveryAddressId = $this->cartFacade->getDeliveryAddressId();
        $cart->setDeliveryModuleId(null)->save();
        $this->deliveryModuleId = null;

        $this->emit('syncSummary');
        $this->emit('updateNextButton');
    }

    #[LiveListener(CheckoutEvents::SET_INVOICE_ORDER_ADDRESS_ID)]
    public function selectInvoiceAddress(#[LiveArg] ?int $addressId): void
    {
        $this->cartFacade->setInvoiceAddress(new CheckoutDTO(
            cart: $this->cartFacade->getOrCreateFromSession(),
            invoiceAddressId: $addressId,
        ));
        $this->invoiceAddressId = $this->cartFacade->getInvoiceAddressId();

        $this->emit('syncSummary');
    }
}
