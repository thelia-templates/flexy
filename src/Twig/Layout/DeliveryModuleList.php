<?php

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

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Enum\DeliveryMode;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Service\Model\AddressService;
use Thelia\Service\Model\CartService;
use Thelia\Service\Model\DeliveryService;

#[AsLiveComponent(template: '@components/Layout/DeliveryModulesList/DeliveryModulesList.html.twig')]
class DeliveryModuleList extends BaseFrontController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public ?array $modules = [];

    #[LiveProp(writable: true, onUpdated: 'onDeliveryModuleSelected')]
    public ?int $deliveryModuleId = null;

    public function __construct(
        private readonly Session         $session,
        private readonly AddressService  $addressService,
        private readonly CartService     $cartService,
        private readonly DeliveryService $deliveryModuleService,
    )
    {
    }

    public function mount(?string $deliveryMode = null): void
    {
        $this->deliveryModuleId = $this->cartService->getCart()->getDeliveryModuleId();

        $collection = $this->deliveryModuleService->getValidDeliveryModuleCollection();

        if (!$deliveryMode) {
            $this->modules = array_merge($collection->getLocalPickup(), $collection->getPickup(), $collection->getDelivery());
            return;
        }

        $mode = DeliveryMode::fromString($deliveryMode);

        $this->modules = match ($mode) {
            DeliveryMode::DELIVERY, null => $collection->getDelivery(),
            DeliveryMode::PICKUP => $collection->getPickup(),
            DeliveryMode::LOCAL_PICKUP => $collection->getLocalPickup(),
            default => null,
        };
    }

    public function onDeliveryModuleSelected(): void
    {
        if ($this->deliveryModuleId) {
            $this->cartService->setDeliveryModule($this->deliveryModuleId);
            $this->emit('resetCart');
        }
    }
}
