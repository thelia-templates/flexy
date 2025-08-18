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

namespace FlexyBundle\Twig\Layout;

use Propel\Runtime\Map\TableMap;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Exception\Checkout\InvalidDeliveryException;
use Thelia\Exception\Checkout\MissingAddressException;
use Thelia\Service\Model\CartService;
use Thelia\Service\Model\CheckoutService;
use Thelia\Service\Model\DeliveryService;
use TwigEngine\Service\DataAccess\AttributeAccessService;
use TwigEngine\Service\DataAccess\DataAccessService;

#[AsLiveComponent(template: '@components/Layout/Checkout/Checkout.html.twig')]
class Checkout
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public string $page = 'cart';

    #[LiveProp]
    public int $step = 1;

    #[LiveProp]
    public array $cart;

    #[LiveProp]
    public bool $deliveryModuleView = false;

    #[LiveProp(writable: true)]
    public ?string $deliveryMode = null;

    #[LiveProp]
    public array $summary = [
        'item_count' => null,
        'raw_taxed_total_price' => null,
        'total_taxed_price' => null,
        'total_tax_amount' => null,
        'taxed_postage' => null,
    ];

    #[LiveProp]
    public array $deliveryModules = [];

    public function __construct(
        private readonly DataAccessService $dataAccessService,
        private readonly Session $session,
        private readonly AttributeAccessService $attributeAccessService,
        private readonly CartService $cartService,
        private readonly CheckoutService $checkoutService,
        private readonly DeliveryService $deliveryModuleService,
    ) {
    }

    public function mount(string $page, string $step): void
    {
        $this->page = $page;
        $this->step = (int) $step;

        $this->resetCart();

        $cart = $this->cartService->getCart();
        if ($cart->isVirtual() && $this->page !== 'cart') {
            $this->deliveryModuleService->setupVirtualDelivery();

            $this->page = 'payment';
            $this->step = 3;
        }

        $collection = $this->deliveryModuleService->getValidDeliveryModuleCollection();
        $this->deliveryModules = [
            'delivery' => $collection->getDelivery(),
            'pickup' => $collection->getPickup(),
            'localPickup' => $collection->getLocalPickup(),
            'hasDelivery' => $collection->hasDelivery(),
            'hasPickup' => $collection->hasPickup(),
            'hasLocalPickup' => $collection->hasLocalPickup(),
        ];

        $selectedDeliveryModuleId = $this->cartService->getCart()->getDeliveryModuleId();
        if ($selectedDeliveryModuleId) {
            $this->deliveryMode = $this->deliveryModuleService->findDeliveryModeByModuleId($selectedDeliveryModuleId, $collection);
        }
    }

    #[LiveListener('resetCart')]
    public function resetCart(): void
    {
        $this->setCart();
        $this->setSummary();
    }

    #[LiveListener('resetSummary')]
    public function resetSummary(): void
    {
        $this->setSummary();
    }

    #[LiveListener('navigateToConfirm')]
    public function navigateToConfirm(): void
    {
        $this->page = 'confirm';
        $this->step = 4;
    }

    #[LiveListener('showDeliveryModuleView')]
    public function showDeliveryModuleView(): void
    {
        if ($this->cartService->getCart()->getAddressDeliveryId()) {
            $this->deliveryModuleView = true;
        }
    }

    #[LiveListener('hideDeliveryModuleView')]
    public function hideDeliveryModuleView(): void
    {
        $this->deliveryModuleView = false;
        $this->deliveryMode = null;
    }

    #[LiveListener('forceCustomerDefaultDeliveryAddress')]
    public function forceCustomerDefaultDeliveryAddress(): void
    {
        $this->deliveryModuleService->setCustomerDefaultDeliveryAddress();
        $this->showDeliveryModuleView();
    }

    #[LiveListener('setSessionVariable')]
    public function setSessionVariable(string $key, mixed $value): void
    {
        $this->deliveryModuleService->setDeliveryData($key, $value);
    }

    #[LiveListener('setSessionVariables')]
    public function setSessionVariables(array $variables): void
    {
        foreach ($variables as $key => $value) {
            $this->deliveryModuleService->setDeliveryData($key, $value);
        }
    }

    #[LiveListener('clearSessionData')]
    public function clearSessionData(): void
    {
        $this->deliveryModuleService->clearDeliveryData();
    }

    public function getCart(): array
    {
        return $this->cart;
    }

    public function setPage(string $page): string
    {
        $this->page = $page;

        return $this->page;
    }

    public function isReadyToCreateOrder(): bool
    {
        try {
            $this->cartService->checkValidDelivery();
            $this->cartService->checkInvoiceAddress();
            $this->cartService->checkValidPayment();

            return true;
        } catch (MissingAddressException|InvalidDeliveryException|\Exception) {
            return false;
        }
    }

    public function getSessionVariables(): array
    {
        return $this->deliveryModuleService->getAllDeliveryData();
    }

    public function setDeliveryMode(?string $deliveryMode): self
    {
        $this->deliveryMode = $deliveryMode;

        return $this;
    }

    protected function setCart(): void
    {
        $sessionCart = $this->cartService->getCart();
        $items = $sessionCart->getCartItems();
        $this->cart = [...$sessionCart->toArray(TableMap::TYPE_CAMELNAME), 'items' => $items->toArray(null, false, TableMap::TYPE_CAMELNAME)];
    }

    protected function setSummary(): void
    {
        foreach ($this->summary as $key => &$value) {
            $value = $this->attributeAccessService->attributeCart($key);
        }
    }
}
