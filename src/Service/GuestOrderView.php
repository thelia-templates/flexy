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
use Thelia\Domain\Order\Service\OrderTrackingUrlResolver;
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
 * the account page show the same numbers for the same order: not by repeating its formulas but
 * by asking the model of the core, which the resource asks as well. A test compares the two,
 * key by key.
 */
final readonly class GuestOrderView
{
    /**
     * The tracking resolver is optional so the page keeps working on a core that
     * predates the parcel tracking link.
     */
    public function __construct(private ?OrderTrackingUrlResolver $trackingUrlResolver = null)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Order $order): array
    {
        return [
            'id' => $order->getId(),
            'ref' => $order->getRef(),
            'currencyCode' => $order->getCurrency()?->getCode() ?: 'EUR',
            'createdAt' => $order->getCreatedAt(),
            'deliveryRef' => $order->getDeliveryRef(),
            'deliveryTrackingUrl' => $this->trackingUrlResolver?->resolve($order),
            'orderStatus' => [
                'code' => $order->getOrderStatus()?->getCode(),
                'equivalentCode' => $order->getOrderStatus()?->getEquivalentCode(),
            ],
            'deliveryOrderAddress' => ['id' => $order->getDeliveryOrderAddressId()],
            'invoiceOrderAddress' => ['id' => $order->getInvoiceOrderAddressId()],
            'orderProducts' => $this->orderProducts($order),
            'totalAmount' => $order->getTotalAmountWithTaxesWithShipping(),
            'totalAmountWithoutTaxes' => $order->getTotalAmountWithoutTaxes(),
            'totalGoodsWithoutTaxes' => $order->getTotalGoodsWithoutTaxes(),
            'amountDiscountWithTaxes' => $order->getAmountDiscountWithTaxes(),
            'amountDiscountWithoutTaxes' => $order->getAmountDiscountWithoutTaxes(),
            'totalAmountWithTaxesAfterDiscount' => $order->getTotalAmountWithTaxesAfterDiscount(),
            'totalAmountWithoutTaxesAfterDiscount' => $order->getTotalAmountWithoutTaxesAfterDiscount(),
            'totalAmountWithoutTaxesWithShipping' => $order->getTotalAmountWithoutTaxesWithShipping(),
            'totalShippingWithTaxes' => $order->getTotalShippingWithTaxes(),
            'totalShippingWithoutTaxes' => $order->getTotalShippingWithoutTaxes(),
            'totalTaxAmount' => $order->getTotalTaxAmount(),
            'postageTax' => $order->getTotalShippingTaxAmount(),
            // Frozen when the order was placed: the buyer reads it the way it was sold to them.
            'customerGroupDisplayTaxedPrice' => (bool) $order->getCustomerGroupDisplayTaxedPrice(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function orderProducts(Order $order): array
    {
        $lines = [];

        foreach ($order->getOrderProducts() as $orderProduct) {
            $chargedTotals = $orderProduct->getChargedLineTotals();
            $promoPrice = $orderProduct->getPromoPrice();

            $lines[] = [
                ...$orderProduct->toArray(TableMap::TYPE_CAMELNAME),
                // What the order resource publishes beside the columns, so that a guest and an
                // account holder read the same line: the model rounds the unit amounts the way
                // the order total was built, and totals the line as it was charged.
                'price' => $orderProduct->roundUnitAmount((float) $orderProduct->getPrice()),
                'promoPrice' => null === $promoPrice ? null : $orderProduct->roundUnitAmount((float) $promoPrice),
                'unitTaxedPrice' => $orderProduct->roundUnitAmount($orderProduct->getTaxedUnitPrice((bool) $orderProduct->getWasInPromo())),
                'taxedPromoPrice' => null === $promoPrice ? null : $orderProduct->roundUnitAmount($orderProduct->getTaxedUnitPrice(true)),
                'unitTaxedPriceBeforePromotion' => $orderProduct->roundUnitAmount($orderProduct->getTaxedUnitPrice(false)),
                'lineTotalWithoutTaxes' => $chargedTotals['untaxed'],
                'lineTotalWithTaxes' => $chargedTotals['taxed'],
            ];
        }

        return $lines;
    }
}
