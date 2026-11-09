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

use FlexyBundle\Service\PriceDisplay;
use FlexyBundle\Service\TaxDisplay;
use PHPUnit\Framework\TestCase;

/**
 * The tiers of a price list as the buyer reads them: the figure of their display out of the two
 * the core published for each tier, in the order of the quantities. Nothing is calculated, and a
 * tier is never printed with the figure of the other display.
 */
final class PriceDisplayTiersTest extends TestCase
{
    private const TIERS = [
        ['minQuantity' => 10, 'price' => 9.0, 'promoPrice' => 9.0, 'taxedPrice' => 10.8, 'taxedPromoPrice' => 10.8],
        ['minQuantity' => 1, 'price' => 10.0, 'promoPrice' => 10.0, 'taxedPrice' => 12.0, 'taxedPromoPrice' => 12.0],
    ];

    public function testAnUntaxedBuyerReadsTheUntaxedFigureOfEachTierInTheOrderOfTheQuantities(): void
    {
        $tiers = $this->priceDisplay()->tiers(TaxDisplay::Untaxed, self::TIERS);

        self::assertSame([1, 10], array_map(static fn ($tier): int => $tier->minQuantity, $tiers));
        self::assertSame([10.0, 9.0], array_map(static fn ($tier): float => $tier->price->amount, $tiers));
        self::assertSame(TaxDisplay::Untaxed, $tiers[0]->price->display);
    }

    public function testATaxedBuyerReadsTheTaxedFigureOfEachTier(): void
    {
        $tiers = $this->priceDisplay()->tiers(TaxDisplay::Taxed, self::TIERS);

        self::assertSame([12.0, 10.8], array_map(static fn ($tier): float => $tier->price->amount, $tiers));
        self::assertSame(TaxDisplay::Taxed, $tiers[1]->price->display);
    }

    public function testATierWhoseFigureOfTheDisplayIsNotPublishedIsLeftOutRatherThanPrintedWithTheOtherOne(): void
    {
        $tiers = $this->priceDisplay()->tiers(TaxDisplay::Taxed, [
            ['minQuantity' => 1, 'price' => 10.0],
            ['minQuantity' => 5, 'price' => 9.5, 'taxedPrice' => 11.4],
        ]);

        self::assertCount(1, $tiers);
        self::assertSame(5, $tiers[0]->minQuantity);
    }

    public function testAPriceListThatDoesNotApplyHasNoTier(): void
    {
        self::assertSame([], $this->priceDisplay()->tiers(TaxDisplay::Taxed, []));
    }

    private function priceDisplay(): PriceDisplay
    {
        // The visitor is the core's business; the tiers touch no collaborator.
        return (new \ReflectionClass(PriceDisplay::class))->newInstanceWithoutConstructor();
    }
}
