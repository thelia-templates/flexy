<?php

namespace FlexyBundle\UiComponents\Checkout\Delivery\PickupDelivery;

use FlexyBundle\UiComponents\Checkout\CheckoutEvents;
use FlexyBundle\UiComponents\Checkout\Delivery\DeliveryMode\DeliveryModeTrait;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Addressing\Service\AddressService;
use Thelia\Domain\Cart\CartFacade;

#[AsLiveComponent(name: 'Flexy:Checkout:Delivery:PickupDelivery', template: '@UiComponents/Checkout/Delivery/PickupDelivery/PickupDelivery.html.twig')]
class PickupDelivery
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DeliveryModeTrait;

    public string $type = 'PickupDelivery';

    #[LiveProp(updateFromParent: true)]
    public int $moduleId;

    #[LiveProp(updateFromParent: true)]
    public string $optionCode;

    #[LiveProp(updateFromParent: true)]
    public string $title;

    #[LiveProp(updateFromParent: true)]
    public string $price;

    #[LiveProp(updateFromParent: true)]
    public ?string $deliveryAddressId;

    #[LiveProp(updateFromParent: true)]
    public ?string $invoiceAddressId;


    #[LiveProp(updateFromParent: true)]
    public ?string $icon = null;

    public function __construct(
        private readonly Session $session,
        private readonly AddressService $addressService,
        private readonly CartFacade $cartFacade
    ) {
    }

    public function mount(string $icon, int $moduleId): void
    {
        $this->icon = $icon;
        $this->moduleId = $moduleId;
    }

    #[LiveListener(CheckoutEvents::SET_DELIVERY_MODULE_OPTION)]
    public function getChecked(): bool
    {
        return $this->cartFacade->getDeliveryModuleId() === $this->moduleId;
    }

}
