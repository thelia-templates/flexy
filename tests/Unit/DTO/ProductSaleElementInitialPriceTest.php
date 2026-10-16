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

use FlexyBundle\DTO\ProductSaleElementDTO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The front API answers `displayInitialPrice` on a sale element whose price a catalog rule or a
 * reserved operation sets: false means the catalog price must not be struck through next to the
 * price charged. A sale element priced by the catalog alone answers null, and keeps its strike.
 */
#[CoversClass(ProductSaleElementDTO::class)]
final class ProductSaleElementInitialPriceTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function payloads(): iterable
    {
        yield 'a rule that hides the catalog price' => [['promo' => true, 'displayInitialPrice' => false], false];
        yield 'a rule that shows the catalog price' => [['promo' => true, 'displayInitialPrice' => true], true];
        yield 'a null answer, the catalog promo stands' => [['promo' => true, 'displayInitialPrice' => null], true];
        yield 'no such key at all' => [['promo' => true], true];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('payloads')]
    public function testTheCatalogPriceIsStruckOnlyWhenThePayloadAllowsIt(array $payload, bool $expected): void
    {
        self::assertSame($expected, ProductSaleElementDTO::fromArray($payload)->displayInitialPrice);
    }
}
