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

namespace FlexyBundle\Components\Organisms\CheckoutSummary;

use FlexyBundle\Event\CheckoutEvents;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Api\Service\DataAccess\AttributeAccessService;

#[AsLiveComponent]
class Base
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    public function __construct(
        private readonly AttributeAccessService $attributeAccessService,
    ) {
    }

    #[LiveListener(CheckoutEvents::DELETE_ITEM_EVENT)]
    #[LiveListener(CheckoutEvents::UPDATE_ITEM_QUANTITY_EVENT)]
    #[LiveListener(CheckoutEvents::ADD_ITEM_EVENT)]
    #[LiveListener('syncSummary')]
    public function getSummary(): array
    {
        return [
            'item_count' => $this->attributeAccessService->attributeCart('item_count'),
            'raw_taxed_total_price' => $this->attributeAccessService->attributeCart('raw_taxed_total_price'),
            'total_taxed_price' => $this->attributeAccessService->attributeCart('total_taxed_price'),
            'total_tax_amount' => $this->attributeAccessService->attributeCart('total_tax_amount'),
            'taxed_postage' => $this->attributeAccessService->attributeCart('taxed_postage'),
            'taxed_discount' => $this->attributeAccessService->attributeCart('taxed_discount'),
            'discount' => $this->attributeAccessService->attributeCart('discount'),
            'coupons' => $this->attributeAccessService->attributeCoupon('coupon_list'),
        ];
    }

    public function hasTax(): bool
    {
        $taxAmount = $this->attributeAccessService->attributeCart('total_tax_amount');

        return $taxAmount !== null && $taxAmount > 0;
    }

    public function hasDiscount(): bool
    {
        $discount = $this->attributeAccessService->attributeCart('taxed_discount');

        return $discount !== null && $discount > 0;
    }
}
