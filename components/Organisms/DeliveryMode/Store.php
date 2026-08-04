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

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Api\Service\DataAccess\AttributeAccessService;
use Thelia\Domain\Cart\CartFacade;

#[AsTwigComponent]
class Store
{
    public string $type = 'StoreDelivery';
    public int $moduleId = 0;
    public string $optionCode = '';
    public string $title = '';
    public string $description = '';
    public string $price = '';

    public function __construct(
        private readonly AttributeAccessService $attributeAccessService,
        private readonly CartFacade $cartFacade,
    ) {
    }

    public function getAddress(): array
    {
        return [
            'address1' => $this->attributeAccessService->attributeConfig('store_address1'),
            'address2' => $this->attributeAccessService->attributeConfig('store_address2'),
            'address3' => $this->attributeAccessService->attributeConfig('store_address3'),
            'zipCode' => $this->attributeAccessService->attributeConfig('store_zipcode'),
            'city' => $this->attributeAccessService->attributeConfig('store_city'),
        ];
    }

    public function getChecked(): bool
    {
        return $this->cartFacade->getDeliveryModuleId() === $this->moduleId;
    }
}
