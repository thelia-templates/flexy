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

namespace FlexyBundle\Service;

use Propel\Runtime\Map\TableMap;
use Thelia\Model\Order;

/**
 * The one order a tracking link opens, in the shape the order components read.
 *
 * The account page gets the same figures from `/api/front/account/orders/{id}`, which
 * only answers a signed-in customer — rightly so. A guest holds a link, not an account,
 * so the figures are computed here from the order the link named, and from nothing else:
 * this never queries by customer, and never returns more than the single order given.
 *
 * The totals mirror Thelia\Api\Resource\Order field by field, so the tracking page and
 * the account page show the same numbers for the same order.
 */
final readonly class GuestOrderView
{
    /**
     * @return array<string, mixed>
     */
    public function build(Order $order): array
    {
        $itemsTax = 0.0;
        $itemsAmount = $order->getTotalAmount($itemsTax, false, false);

        return [
            'id' => $order->getId(),
            'ref' => $order->getRef(),
            'createdAt' => $order->getCreatedAt(),
            'deliveryRef' => $order->getDeliveryRef(),
            'orderStatus' => ['code' => $order->getOrderStatus()?->getCode()],
            'deliveryOrderAddress' => ['id' => $order->getDeliveryOrderAddressId()],
            'invoiceOrderAddress' => ['id' => $order->getInvoiceOrderAddressId()],
            'orderProducts' => $this->orderProducts($order),
            'totalAmount' => round($order->getTotalAmount(), 2),
            'totalAmountWithoutTaxes' => round($itemsAmount - $itemsTax, 2),
            'amountDiscountWithTaxes' => round((float) $order->getDiscount(), 2),
            'totalShippingWithTaxes' => round((float) $order->getPostage(), 2),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function orderProducts(Order $order): array
    {
        $lines = [];

        foreach ($order->getOrderProducts() as $orderProduct) {
            $lines[] = $orderProduct->toArray(TableMap::TYPE_CAMELNAME);
        }

        return $lines;
    }
}
