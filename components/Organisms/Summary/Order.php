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

namespace FlexyBundle\Components\Organisms\Summary;

use FlexyBundle\DTO\DisplayedPrice;
use FlexyBundle\Service\PriceDisplay;
use FlexyBundle\Service\TaxDisplay;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Totals of a placed order. Deliberately not a child of Checkout: that one is a
 * LiveComponent reading the live cart, whereas these figures are frozen.
 */
#[AsTwigComponent]
class Order
{
    /** @var array<string, mixed> */
    public array $order = [];

    /** The currency the order was placed in; the pages that do not say keep euros. */
    public string $currencyCode = 'EUR';

    public function __construct(
        private readonly PriceDisplay $priceDisplay,
    ) {
    }

    /**
     * How this order is read: the display its buyer's group had when it was placed, not the
     * one the buyer has now.
     */
    public function getDisplay(): TaxDisplay
    {
        return $this->priceDisplay->forOrder($this->order);
    }

    public function getShipping(): DisplayedPrice
    {
        return $this->priceDisplay->orderShipping($this->getDisplay(), $this->order);
    }

    public function getDiscount(): DisplayedPrice
    {
        return $this->priceDisplay->orderDiscount($this->getDisplay(), $this->order);
    }

    public function getTotal(): DisplayedPrice
    {
        return $this->priceDisplay->orderTotal($this->getDisplay(), $this->order);
    }

    /**
     * The goods before the discount, which the core publishes untaxed only.
     */
    public function getSubtotal(): DisplayedPrice
    {
        return $this->priceDisplay->untaxed($this->getGoodsUntaxedTotal());
    }

    public function getItemCount(): int
    {
        $count = 0;

        foreach ($this->goodsLines() as $orderProduct) {
            $count += (int) ($orderProduct['quantity'] ?? 0);
        }

        return $count;
    }

    /**
     * The services the shop invoiced beside the goods — a gift wrapping — each with what
     * the buyer paid for it, in the display the order is read in.
     *
     * Their wording is the one frozen on the order, not the one the shop shows today: a
     * merchant who has renamed or deleted the service since must not change what a placed
     * order reads.
     *
     * @return list<array{title: string, price: DisplayedPrice}>
     */
    public function getServices(): array
    {
        $services = [];

        foreach ($this->order['orderProducts'] ?? [] as $orderProduct) {
            if (!$this->isServiceLine($orderProduct)) {
                continue;
            }

            $services[] = [
                'title' => (string) ($orderProduct['title'] ?? ''),
                'price' => $this->priceDisplay->orderServiceLine($this->getDisplay(), $orderProduct),
            ];
        }

        return $services;
    }

    /**
     * The subtotal of the goods alone.
     *
     * The order totals count a service line exactly like a product line — which is the
     * point of invoicing it as one — so the summary reads the total the core publishes
     * without the services, or it would state them twice: once in the subtotal and once on
     * their own line.
     */
    public function getGoodsUntaxedTotal(): float
    {
        return (float) ($this->order['totalGoodsWithoutTaxes'] ?? 0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function goodsLines(): array
    {
        return array_values(array_filter(
            $this->order['orderProducts'] ?? [],
            fn (array $orderProduct): bool => !$this->isServiceLine($orderProduct),
        ));
    }

    /**
     * A core whose payload predates the property says nothing, and every line then reads
     * as a good — which, on an order placed before the feature existed, it is.
     *
     * @param array<string, mixed> $orderProduct
     */
    private function isServiceLine(array $orderProduct): bool
    {
        return 'service' === ($orderProduct['lineType'] ?? 'product');
    }

    /**
     * What the taxes of the order come to, the postage included, so that the untaxed total
     * and this amount add up to the total to pay.
     */
    public function getTaxAmount(): float
    {
        return $this->priceDisplay->orderTaxAmount($this->order);
    }

    /**
     * The order without its taxes, whatever the display it is read in: the figure of the
     * summary of a buyer shown the prices untaxed.
     */
    public function getTotalWithoutTaxes(): DisplayedPrice
    {
        return $this->priceDisplay->orderTotal(TaxDisplay::Untaxed, $this->order);
    }

    /**
     * What the buyer of an order read untaxed has to pay, with its taxes.
     */
    public function getTotalToPay(): DisplayedPrice
    {
        return $this->priceDisplay->orderTotal(TaxDisplay::Taxed, $this->order);
    }

    public function hasTax(): bool
    {
        return $this->getDisplay()->isTaxed() && 0.0 !== $this->getTaxAmount();
    }

    public function hasDiscount(): bool
    {
        return (float) ($this->order['amountDiscountWithTaxes'] ?? 0) > 0;
    }

    public function hasShipping(): bool
    {
        return (float) ($this->order['totalShippingWithTaxes'] ?? 0) > 0;
    }

    public function isVatExempted(): bool
    {
        return (bool) ($this->order['vatExempted'] ?? false);
    }

    public function getInvoiceVatNumber(): string
    {
        return (string) ($this->order['invoiceOrderAddress']['vatNumber'] ?? '');
    }
}
