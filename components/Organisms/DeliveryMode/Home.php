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

namespace FlexyBundle\Components\Organisms\DeliveryMode;

use FlexyBundle\Event\CheckoutEvents;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Domain\Cart\CartFacade;

#[AsLiveComponent]
class Home
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    public string $type = 'HomeDelivery';

    #[LiveProp(updateFromParent: true)]
    public int $moduleId = 0;

    #[LiveProp(updateFromParent: true)]
    public string $optionCode = '';

    #[LiveProp(updateFromParent: true)]
    public string $title = '';

    #[LiveProp(updateFromParent: true)]
    public string $price = '';

    #[LiveProp(updateFromParent: true)]
    public ?int $deliveryAddressId = null;

    #[LiveProp(updateFromParent: true)]
    public ?int $invoiceAddressId = null;

    public function __construct(
        private readonly CartFacade $cartFacade,
    ) {
    }

    #[LiveListener(CheckoutEvents::SET_DELIVERY_MODULE_OPTION)]
    public function getChecked(): bool
    {
        return $this->cartFacade->getDeliveryModuleId() === $this->moduleId;
    }
}
