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

namespace FlexyBundle\Tests\Unit\DTO;

use FlexyBundle\DTO\ProductPriceDTO;
use FlexyBundle\DTO\ProductSaleElementDTO;
use PHPUnit\Framework\TestCase;

/**
 * What the theme reads of the price of a sale element, as the front API publishes it: the mode that
 * says who is shown the price, whether the item may be bought, the tiers of the price list, and the
 * prices themselves, which are simply absent when the caller is not shown them. An absent price is
 * null, never zero: a zero would print as a price.
 */
final class ProductSaleElementPriceFieldsTest extends TestCase
{
    public function testTheModeThePurchasableFlagAndTheTiersAreRead(): void
    {
        $tiers = [['minQuantity' => 1, 'price' => 10.0, 'taxedPrice' => 12.0], ['minQuantity' => 10, 'price' => 9.0, 'taxedPrice' => 10.8]];

        $saleElement = ProductSaleElementDTO::fromArray(['id' => 3, 'priceDisplayMode' => 'logged_in', 'purchasable' => false, 'priceTiers' => $tiers]);

        self::assertSame('logged_in', $saleElement->priceDisplayMode);
        self::assertFalse($saleElement->purchasable);
        self::assertSame($tiers, $saleElement->priceTiers);
    }

    public function testACoreThatSaysNothingIsReadAsVisiblePurchasableAndWithoutTiers(): void
    {
        $saleElement = ProductSaleElementDTO::fromArray(['id' => 3]);

        self::assertSame('visible', $saleElement->priceDisplayMode);
        self::assertTrue($saleElement->purchasable);
        self::assertSame([], $saleElement->priceTiers);
    }

    public function testAPriceTheApiLeavesOutIsNullAndNotZero(): void
    {
        $price = ProductPriceDTO::fromArray(['currency' => 'EUR']);

        self::assertNull($price->price);
        self::assertNull($price->promoPrice);
        self::assertNull($price->taxedPrice);
        self::assertNull($price->taxedPromoPrice);
    }

    public function testAPriceTheApiPublishesIsRead(): void
    {
        $price = ProductPriceDTO::fromArray(['currency' => 'EUR', 'price' => '80.5', 'promoPrice' => 70, 'taxedPrice' => 96.6, 'taxedPromoPrice' => 84]);

        self::assertSame(80.5, $price->price);
        self::assertSame(70.0, $price->promoPrice);
        self::assertSame(96.6, $price->taxedPrice);
        self::assertSame(84.0, $price->taxedPromoPrice);
    }
}
