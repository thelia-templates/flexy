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

namespace FlexyBundle\Tests\Unit;

use FlexyBundle\DTO\CartItemDto;
use PHPUnit\Framework\TestCase;

/**
 * A line of the cart that stands for a line of a quote is frozen: the cart page draws no control on it, and the cart
 * component answers no action to it. The column that says so is the one the line carries.
 */
final class CartItemFrozenTest extends TestCase
{
    public function testALineTiedToALineOfAQuoteIsFrozen(): void
    {
        self::assertTrue(CartItemDto::fromArray(['id' => 3, 'quantity' => 12, 'quoteItemId' => 4605])->isFrozen);
    }

    public function testAnOrdinaryLineIsNot(): void
    {
        self::assertFalse(CartItemDto::fromArray(['id' => 3, 'quantity' => 12, 'quoteItemId' => null])->isFrozen);
        self::assertFalse(CartItemDto::fromArray(['id' => 3, 'quantity' => 12])->isFrozen, 'A core that has no quotes publishes no such column.');
    }
}
