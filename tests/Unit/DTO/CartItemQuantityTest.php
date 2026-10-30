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

use FlexyBundle\DTO\CartItemDto;
use FlexyBundle\DTO\QuantityRuleDTO;
use FlexyBundle\Service\CartQuantity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A cart line keeps its decimals and the rule it moves by; the rule moves a quantity by whole
 * steps without float drift, and writes it in a field the way the page writes numbers.
 */
final class CartItemQuantityTest extends TestCase
{
    public function testALineKeepsItsDecimalsAndItsRule(): void
    {
        $line = CartItemDto::fromArray([
            'quantity' => '1.250000',
            'stock' => 7.5,
            'quantityRule' => ['minQuantity' => 0.5, 'quantityStep' => 0.25, 'quantityDecimals' => 2, 'unitCode' => 'kg'],
        ]);

        self::assertSame(1.25, $line->quantity);
        self::assertSame(7.5, $line->stock);
        self::assertSame(0.25, $line->rule()->step);
        self::assertSame(2, $line->rule()->decimals);
    }

    public function testALineWithoutARuleIsSoldOneAtATime(): void
    {
        self::assertEquals(new QuantityRuleDTO(), CartItemDto::fromArray(['quantity' => 2])->rule());
        self::assertEquals(new QuantityRuleDTO(), CartItemDto::fromArray(['quantity' => 2, 'quantityRule' => 'tampered'])->rule());
    }

    public function testAQuantityMovesByWholeSteps(): void
    {
        $quarter = new QuantityRuleDTO(minQuantity: 0.5, step: 0.25, decimals: 2);
        $tenth = new QuantityRuleDTO(step: 0.1, decimals: 1);

        self::assertSame(1.5, $quarter->moveBy(1.25, 1));
        self::assertSame(1.0, $quarter->moveBy(1.25, -1));
        self::assertSame(0.3, $tenth->moveBy(0.2, 1), '0.2 + 0.1 is 0.3, not 0.30000000000000004');
        self::assertSame(12.0, (new QuantityRuleDTO(minQuantity: 2, step: 6))->moveBy(6, 1));
    }

    /**
     * @return iterable<string, array{QuantityRuleDTO, float, string|null, string}>
     */
    public static function fieldValues(): iterable
    {
        $quarter = new QuantityRuleDTO(minQuantity: 0.5, step: 0.25, decimals: 2);

        yield 'half a kilogram on a French page' => [$quarter, 0.5, 'fr_FR', '0,50'];
        yield 'on an English page' => [$quarter, 0.75, 'en_US', '0.75'];
        yield 'no language known' => [$quarter, 1.25, null, '1.25'];
        yield 'a count of pieces' => [new QuantityRuleDTO(step: 6), 12, 'fr_FR', '12'];
        yield 'no grouping of thousands' => [$quarter, 1234.5, 'fr_FR', '1234,50'];
    }

    #[DataProvider('fieldValues')]
    public function testAFieldIsWrittenWithTheDecimalsOfTheRule(QuantityRuleDTO $rule, float $quantity, ?string $locale, string $expected): void
    {
        self::assertSame($expected, $rule->fieldValue($quantity, $locale));
    }

    public function testATypedQuantityReadsBothSeparators(): void
    {
        self::assertSame(1.25, CartQuantity::typed('1,25'));
        self::assertSame(1.25, CartQuantity::typed(' 1.25 '));
        self::assertSame(3.0, CartQuantity::typed(3));
        self::assertNull(CartQuantity::typed('1,250.5'));
        self::assertNull(CartQuantity::typed('abc'));
    }
}
