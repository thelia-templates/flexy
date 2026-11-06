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

use Thelia\Domain\Cart\DTO\CartItemAddDTO;

/**
 * A quantity on its way between the shopper and the cart: read as the shopper typed it, and
 * handed to the cart in the type the cart of this core takes.
 */
final class CartQuantity
{
    /**
     * A quantity as the shopper typed it, "1,25" or "1.25": a quantity is never written with a
     * thousands separator, so a single comma or point can only be the decimal one. Anything else
     * is null, and left for the cart to refuse.
     */
    public static function typed(mixed $value): ?float
    {
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }

        if (!\is_string($value) || 1 !== preg_match('/^\s*(\d+)(?:[.,](\d+))?\s*$/', $value, $parts)) {
            return null;
        }

        return (float) ($parts[1].'.'.($parts[2] ?? '0'));
    }

    /**
     * The quantity the cart is handed: a whole number stays an int, which a core that counts in
     * whole pieces takes as it always did, and a decimal one goes through only to a core that
     * accepts it. An older one never sells by measure: it gets the whole part.
     */
    public static function forTheCart(float $quantity): int|float
    {
        $whole = self::wholeWhenWhole($quantity);

        if (\is_int($whole) || self::cartTakesDecimals()) {
            return $whole;
        }

        return (int) $quantity;
    }

    /** 3.0 is told as 3 to the page, which counts pieces in whole numbers. */
    public static function wholeWhenWhole(float $quantity): int|float
    {
        return floor($quantity) === $quantity && abs($quantity) < \PHP_INT_MAX ? (int) $quantity : $quantity;
    }

    /**
     * The cart DTOs of a core that sells by measure take a float; those of an older core an int,
     * which a decimal would break under strict types.
     */
    public static function cartTakesDecimals(): bool
    {
        static $takesDecimals = null;

        return $takesDecimals ??= 'float' === (string) (new \ReflectionParameter([CartItemAddDTO::class, '__construct'], 'quantity'))->getType();
    }
}
