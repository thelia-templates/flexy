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

use FlexyBundle\DTO\DisplayedPrice;
use Thelia\Api\Resource\DeliveryModuleOption;
use Thelia\Domain\Catalog\Product\VisitorPriceReader;

/**
 * The one place where the theme decides between a price with the taxes and a price without.
 *
 * The amounts all come from the core, which has already applied the group, the discounts, the
 * rules and the operations, and publishes each of them with and without its taxes: this
 * service never prices anything and never calculates, it only picks the figure the buyer is
 * meant to read and says which of the two it is. A test (NoAmountArithmeticTest) keeps it so.
 *
 * Who decides: the group of the customer for what is on offer, the value frozen on the order
 * for what has been bought. A buyer who changes group later still reads the old order the
 * way it was placed.
 */
final readonly class PriceDisplay
{
    public function __construct(
        private VisitorPriceReader $visitorPriceReader,
    ) {
    }

    public function forVisitor(): TaxDisplay
    {
        return TaxDisplay::fromTaxed($this->visitorPriceReader->displaysTaxedPrices());
    }

    /**
     * How a placed order is read: the display of the group its buyer belonged to when it was
     * placed. An order that says nothing was placed before the groups existed, and those were
     * all taxed.
     *
     * @param array<string, mixed> $order the order as the front API or the guest view serves it
     */
    public function forOrder(array $order): TaxDisplay
    {
        return TaxDisplay::fromTaxed((bool) ($order['customerGroupDisplayTaxedPrice'] ?? true));
    }

    /**
     * The price the front API publishes for a product, as the visitor reads it: the figure of
     * the display out of the two the core published for the price of a sale element.
     */
    public function ofProduct(TaxDisplay $display, ?float $untaxedPrice, ?float $taxedPrice): ?DisplayedPrice
    {
        return $this->choose($display, $untaxedPrice, $taxedPrice);
    }

    /**
     * One figure out of the two the core published. Null when the figure of the display asked
     * for is not there: a caller never falls back on the other one without saying so.
     */
    public function choose(TaxDisplay $display, ?float $untaxed, ?float $taxed): ?DisplayedPrice
    {
        $amount = $display->isTaxed() ? $taxed : $untaxed;

        return null === $amount ? null : new DisplayedPrice($amount, $display);
    }

    /**
     * A figure of a placed order the core publishes untaxed only: the goods of an order before
     * its discount. It is read untaxed, and says so.
     */
    public function untaxed(float $amount): DisplayedPrice
    {
        return new DisplayedPrice($amount, TaxDisplay::Untaxed);
    }

    /**
     * The postage a carrier quoted, in both forms: the core fills the untaxed one whenever it
     * fills the postage, so there is nothing to take out of the taxed one here.
     */
    public function ofDeliveryOption(TaxDisplay $display, DeliveryModuleOption $option): ?DisplayedPrice
    {
        return $this->choose($display, $option->getPostageUntaxed(), $option->getPostage());
    }

    /**
     * The same, for a figure the core always publishes in both forms.
     */
    public function pick(TaxDisplay $display, float $untaxed, float $taxed): DisplayedPrice
    {
        return new DisplayedPrice($display->isTaxed() ? $taxed : $untaxed, $display);
    }

    /**
     * The delivery of a placed order, as the core publishes it in both forms.
     *
     * @param array<string, mixed> $order
     */
    public function orderShipping(TaxDisplay $display, array $order): DisplayedPrice
    {
        return $this->pick(
            $display,
            (float) ($order['totalShippingWithoutTaxes'] ?? 0),
            (float) ($order['totalShippingWithTaxes'] ?? 0),
        );
    }

    /**
     * What a placed order took off, in the display it is read in.
     *
     * @param array<string, mixed> $order
     */
    public function orderDiscount(TaxDisplay $display, array $order): DisplayedPrice
    {
        return $this->pick(
            $display,
            (float) ($order['amountDiscountWithoutTaxes'] ?? 0),
            (float) ($order['amountDiscountWithTaxes'] ?? 0),
        );
    }

    /**
     * What one unit of a line of a placed order was charged, the promotional price when the
     * line was bought on promotion. The core publishes the taxed figure as it was charged.
     *
     * @param array<string, mixed> $orderProduct
     */
    public function orderLineUnit(TaxDisplay $display, array $orderProduct): DisplayedPrice
    {
        $onPromotion = (bool) ($orderProduct['wasInPromo'] ?? false);

        return $this->pick(
            $display,
            (float) ($onPromotion ? ($orderProduct['promoPrice'] ?? 0) : ($orderProduct['price'] ?? 0)),
            (float) ($orderProduct['unitTaxedPrice'] ?? 0),
        );
    }

    /**
     * What one unit of a line bought on promotion cost before the promotion, struck beside the
     * price paid: both forms are published by the core.
     *
     * @param array<string, mixed> $orderProduct
     */
    public function orderLineOriginalUnit(TaxDisplay $display, array $orderProduct): DisplayedPrice
    {
        return $this->pick(
            $display,
            (float) ($orderProduct['price'] ?? 0),
            (float) ($orderProduct['unitTaxedPriceBeforePromotion'] ?? 0),
        );
    }

    /**
     * A line of a placed order for its whole quantity (a gift wrapping, say), as the core
     * charged it: the two totals of the line, never the unit times the quantity.
     *
     * @param array<string, mixed> $orderProduct
     */
    public function orderServiceLine(TaxDisplay $display, array $orderProduct): DisplayedPrice
    {
        return $this->pick(
            $display,
            (float) ($orderProduct['lineTotalWithoutTaxes'] ?? 0),
            (float) ($orderProduct['lineTotalWithTaxes'] ?? 0),
        );
    }

    /**
     * What the buyer of a placed order pays or paid, in the display it is read in: the total
     * the core publishes with and without its taxes, the postage included in both.
     *
     * @param array<string, mixed> $order
     */
    public function orderTotal(TaxDisplay $display, array $order): DisplayedPrice
    {
        return $this->pick(
            $display,
            (float) ($order['totalAmountWithoutTaxesWithShipping'] ?? 0),
            (float) ($order['totalAmount'] ?? 0),
        );
    }

    /**
     * The tax a placed order carries, as the core publishes it: the postage included and the
     * discount taken off. Added to the untaxed total it gives the taxed total to the cent.
     *
     * @param array<string, mixed> $order
     */
    public function orderTaxAmount(array $order): float
    {
        return (float) ($order['totalTaxAmount'] ?? 0);
    }
}
