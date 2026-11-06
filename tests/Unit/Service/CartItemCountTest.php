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

namespace FlexyBundle\Tests\Unit\Service;

use FlexyBundle\Components\Organisms\Summary\Order;
use FlexyBundle\DTO\OrderProductDTO;
use FlexyBundle\Service\CartItemCount;
use FlexyBundle\Service\PriceDisplay;
use PHPUnit\Framework\TestCase;

/**
 * "N items" sums the quantities, as it always did, until a line is sold by measure: the lines
 * are then counted. Two bottles and three glasses are five items; two bottles and 1.25 kg of
 * cheese are two.
 */
final class CartItemCountTest extends TestCase
{
    public function testPiecesAreSummed(): void
    {
        self::assertSame(5, CartItemCount::count([['quantity' => 2.0, 'decimals' => 0], ['quantity' => 3.0, 'decimals' => 0]]));
        self::assertSame(0, CartItemCount::count([]));
    }

    public function testALineSoldByMeasureCountsTheLines(): void
    {
        self::assertSame(2, CartItemCount::count([['quantity' => 2.0, 'decimals' => 0], ['quantity' => 1.25, 'decimals' => 2]]));
        self::assertSame(2, CartItemCount::count([['quantity' => 2.0, 'decimals' => 0], ['quantity' => 2.0, 'decimals' => 2]]), 'two kilograms are one line, not two items');
        self::assertSame(1, CartItemCount::count([['quantity' => 0.5, 'decimals' => 0]]), 'a decimal on a line without rule counts as a line too');
    }

    public function testAPlacedOrderCountsTheSameWay(): void
    {
        $order = new Order((new \ReflectionClass(PriceDisplay::class))->newInstanceWithoutConstructor());
        $order->order = ['orderProducts' => [
            ['quantity' => 2, 'quantityDecimals' => 0],
            ['quantity' => 1.25, 'quantityDecimals' => 2, 'unitCode' => 'kg'],
        ]];

        self::assertSame(2, $order->getItemCount());

        $order->order = ['orderProducts' => [['quantity' => 2], ['quantity' => 3]]];
        self::assertSame(5, $order->getItemCount(), 'an order placed before keeps its count');
    }

    public function testAnOrderLineKeepsItsDecimalsAndItsUnit(): void
    {
        $line = OrderProductDTO::fromArray(['quantity' => 1.25, 'quantityDecimals' => 2, 'unitCode' => 'kg']);

        self::assertSame(1.25, $line->quantity);
        self::assertSame(2, $line->quantityDecimals);
        self::assertSame('kg', $line->unitCode);
        self::assertNull(OrderProductDTO::fromArray(['quantity' => 3])->unitCode);
    }
}
