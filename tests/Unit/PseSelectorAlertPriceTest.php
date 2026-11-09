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

use FlexyBundle\Components\Organisms\PseSelector\Base;
use PHPUnit\Framework\TestCase;

/**
 * The price the `product.pse.alerts` hook hands to the modules is the price the buyer is shown:
 * null, which the hook documents, when the buyer is shown none.
 */
final class PseSelectorAlertPriceTest extends TestCase
{
    public function testAPriceTheBuyerIsNotShownIsHandedAsNull(): void
    {
        self::assertNull((new Base())->alertPrice(['priceDisplayMode' => 'logged_in', 'purchasable' => false, 'isPromo' => false]));
        self::assertNull((new Base())->alertPrice(['priceDisplayMode' => 'logged_in', 'purchasable' => false], 99.0), 'Not even the price of a bundle.');
    }

    public function testAPriceTheBuyerIsShownIsHandedWithItsTaxes(): void
    {
        $base = new Base();

        self::assertSame(30.0, $base->alertPrice(['priceDisplayMode' => 'visible', 'price' => 30.0, 'isPromo' => false]));
        self::assertSame(24.0, $base->alertPrice(['priceDisplayMode' => 'visible', 'price' => 30.0, 'promoPrice' => 24.0, 'isPromo' => true]));
        self::assertSame(55.0, $base->alertPrice(['priceDisplayMode' => 'visible', 'price' => 30.0, 'isPromo' => false], 55.0));
    }
}
