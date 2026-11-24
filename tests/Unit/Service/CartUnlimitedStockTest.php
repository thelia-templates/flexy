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

use FlexyBundle\Service\CartStockService;
use PHPUnit\Framework\TestCase;
use Thelia\Model\Cart;
use Thelia\Model\CartItem;
use Thelia\Model\ProductSaleElements;

/**
 * A line with no stock to enforce (a virtual product, a shop that does not check its stock)
 * answers a stock the browser sends back as it received it. The answer travels in the `items`
 * LiveProp of the cart, and JavaScript keeps no whole number above Number.MAX_SAFE_INTEGER:
 * a larger one comes back rounded, the checksum of the props no longer matches, and every
 * action on that cart is refused ("Invalid checksum sent when updating the live component").
 */
final class CartUnlimitedStockTest extends TestCase
{
    /** Number.MAX_SAFE_INTEGER, 2^53 - 1. */
    private const LARGEST_NUMBER_A_BROWSER_KEEPS = 9007199254740991;

    protected function setUp(): void
    {
        try {
            class_exists(CartItem::class);
        } catch (\Error) {
            self::markTestSkipped('The generated Propel models are not autoloaded.');
        }
    }

    public function testTheStockOfALineWithNoLimitSurvivesTheBrowser(): void
    {
        // No stock reader of the core: nothing to enforce on any line.
        $answer = (new CartStockService())->stocksOf([[new CartItem(), new ProductSaleElements()]], new Cart())[0];

        self::assertFalse($answer['managed']);
        self::assertIsInt($answer['stock']);
        self::assertLessThanOrEqual(self::LARGEST_NUMBER_A_BROWSER_KEEPS, $answer['stock']);
    }

    public function testTheStockOfALineBeingPutBackWithNoLimitSurvivesTheBrowser(): void
    {
        $answer = (new CartStockService())->stockFor(new ProductSaleElements(), null);

        self::assertFalse($answer['managed']);
        self::assertIsInt($answer['stock']);
        self::assertLessThanOrEqual(self::LARGEST_NUMBER_A_BROWSER_KEEPS, $answer['stock']);
    }
}
