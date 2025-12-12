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

namespace FlexyBundle\UiComponents\Checkout\NextButton;

use FlexyBundle\Service\DeliveryService;
use FlexyBundle\UiComponents\Checkout\CheckoutEvents;
use FlexyBundle\UiComponents\Checkout\CheckoutSteps\CheckoutSteps;
use Propel\Runtime\Exception\PropelException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Shipping\ShippingFacade;

#[AsLiveComponent(name: 'Flexy:Checkout:NextButton', template: '@UiComponents/Checkout/NextButton/NextButton.html.twig')]
class NextButton
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp(updateFromParent: true)]
    public int $step;

    #[LiveProp(updateFromParent: true)]
    public string $href;

    public function __construct(
        private readonly CartFacade $cartFacade,
        private readonly ShippingFacade $shippingFacade,
        private readonly DeliveryService $deliveryService,
    ) {
    }

    public function mount(int $step, string $href): void
    {
        $this->step = $step;
        $this->href = $href;
    }

    #[LiveListener(CheckoutEvents::DELETE_ITEM_EVENT)]
    #[LiveListener(CheckoutEvents::ADD_ITEM_EVENT)]
    #[LiveListener(CheckoutEvents::SET_DELIVERY_MODULE_OPTION)]
    #[LiveListener(CheckoutEvents::SET_DELIVERY_ORDER_ADDRESS_ID)]
    #[LiveListener('updateNextButton')]
    public function getIsValid(): bool
    {
        return match ($this->step) {
            CheckoutSteps::CART => $this->isCartValid(),
            CheckoutSteps::DELIVERY => $this->isDeliveryValid(),
            CheckoutSteps::PAYMENT => $this->isPaymentValid(),
            default => false,
        };
    }

    /**
     * @throws PropelException
     */
    private function isCartValid(): bool
    {
        return $this->cartFacade->getOrCreateFromSession()->countCartItems() > 0;
    }

    private function isDeliveryValid(): bool
    {
        $cart = $this->cartFacade->getOrCreateFromSession();
        $isPickupOk = $this->deliveryService->isValid($cart->getDeliveryModuleId());

        if (
            $this->isCartValid()
            && $cart->getAddressDeliveryId()
            && $cart->getDeliveryModuleId()
            && $isPickupOk
        ) {
            return true;
        }
        // @TODO test local pickup & pickup
        return false;
    }

    private function isPaymentValid(): bool
    {
        $cart = $this->cartFacade->getOrCreateFromSession();
        if (
            $this->isDeliveryValid() && $cart->getAddressDeliveryId()
            && $cart->getPaymentModuleId()
            && $cart->getAddressInvoiceId()
        ) {
            return true;
        }

        return false;
    }
}
