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
     * the buyer paid for it, tax included.
     *
     * Their wording is the one frozen on the order, not the one the shop shows today: a
     * merchant who has renamed or deleted the service since must not change what a placed
     * order reads.
     *
     * @return list<array{title: string, taxed_total: float}>
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
                'taxed_total' => round(
                    (float) ($orderProduct['unitTaxedPrice'] ?? 0) * (float) ($orderProduct['quantity'] ?? 1),
                    2,
                ),
            ];
        }

        return $services;
    }

    /**
     * The subtotal of the goods alone.
     *
     * The order totals count a service line exactly like a product line — which is the
     * point of invoicing it as one — so the untaxed price of the services has to come back
     * out here, or the summary would state them twice: once in the subtotal and once on
     * their own line.
     */
    public function getGoodsUntaxedTotal(): float
    {
        $servicesUntaxed = 0.0;

        foreach ($this->order['orderProducts'] ?? [] as $orderProduct) {
            if (!$this->isServiceLine($orderProduct)) {
                continue;
            }

            $servicesUntaxed += (float) ($orderProduct['price'] ?? 0) * (float) ($orderProduct['quantity'] ?? 1);
        }

        return round((float) ($this->order['totalAmountWithoutTaxes'] ?? 0) - $servicesUntaxed, 2);
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

    public function getTaxAmount(): float
    {
        // totalAmount carries the shipping while totalAmountWithoutTaxes is the bare
        // item subtotal: strip the shipping before reading the taxes out of the
        // difference, or the postage shows up as an included tax.
        return round(
            (float) ($this->order['totalAmount'] ?? 0)
            - (float) ($this->order['totalShippingWithTaxes'] ?? 0)
            - (float) ($this->order['totalAmountWithoutTaxes'] ?? 0),
            2,
        );
    }

    public function hasTax(): bool
    {
        return 0.0 !== $this->getTaxAmount();
    }

    public function hasDiscount(): bool
    {
        return (float) ($this->order['amountDiscountWithTaxes'] ?? 0) > 0;
    }

    public function hasShipping(): bool
    {
        return (float) ($this->order['totalShippingWithTaxes'] ?? 0) > 0;
    }
}
