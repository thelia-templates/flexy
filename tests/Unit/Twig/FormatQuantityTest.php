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

namespace FlexyBundle\Tests\Unit\Twig;

use FlexyBundle\Service\ReferenceUnitPrices;
use FlexyBundle\Twig\QuantityExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thelia\Domain\Catalog\Quantity\QuantityFormatter;
use Thelia\Domain\Localization\Service\LangService;

/**
 * `{{ quantity|format_quantity(decimals, unitCode) }}` writes a quantity with the decimals of its
 * line and the symbol of its unit, in the language of the session.
 */
final class FormatQuantityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, float|int|string, int, string|null, string}>
     */
    public static function quantities(): iterable
    {
        yield 'a weight in French' => ['fr_FR', 1.25, 2, 'kg', '1,25 kg'];
        yield 'a weight in English' => ['en_US', 1.25, 2, 'kg', '1.25 kg'];
        yield 'the decimals of the line, not of the number' => ['fr_FR', 2, 2, 'kg', '2,00 kg'];
        yield 'a square metre' => ['fr_FR', '3.5', 1, 'm2', '3,5 m²'];
        yield 'six pieces' => ['fr_FR', 6, 0, null, '6'];
        yield 'a unit this core does not know' => ['fr_FR', 6, 0, 'furlong', '6'];
    }

    #[DataProvider('quantities')]
    public function testAQuantityIsWrittenWithItsDecimalsAndItsUnit(string $locale, float|int|string $quantity, int $decimals, ?string $unitCode, string $expected): void
    {
        if (!class_exists(QuantityFormatter::class)) {
            self::markTestSkipped('This core has no quantity formatter.');
        }

        $lang = self::createStub(LangService::class);
        $lang->method('getLocale')->willReturn($locale);

        self::assertSame($expected, (new QuantityExtension(new ReferenceUnitPrices(), $lang))->formatQuantity($quantity, $decimals, $unitCode));
    }

    public function testTheFilterIsDeclared(): void
    {
        $names = array_map(static fn ($filter): string => $filter->getName(), (new QuantityExtension(new ReferenceUnitPrices(), self::createStub(LangService::class)))->getFilters());

        self::assertContains('format_quantity', $names);
    }
}
