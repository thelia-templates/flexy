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

        foreach ($this->order['orderProducts'] ?? [] as $orderProduct) {
            $count += (int) ($orderProduct['quantity'] ?? 0);
        }

        return $count;
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
