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
use FlexyBundle\DTO\QuantityRuleDTO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thelia\Domain\Catalog\Quantity\QuantityRule;

/**
 * The rule a sale element is sold by, as the selector reads it: a payload without any rule (a
 * core that predates them) sells one at a time, a lot of six starts at six and moves by six,
 * a weight takes its decimals.
 */
#[CoversClass(QuantityRuleDTO::class)]
#[CoversClass(ProductSaleElementDTO::class)]
final class QuantityRuleTest extends TestCase
{
    public function testAPayloadWithoutARuleSellsOneAtATime(): void
    {
        $rule = QuantityRuleDTO::fromArray(['id' => 3, 'quantity' => 10]);

        self::assertSame(1.0, $rule->smallestQuantity());
        self::assertSame('1', $rule->inputStep());
        self::assertFalse($rule->isSoldByMeasure());
        self::assertFalse($rule->offersAList());
        self::assertSame([1.0, 2.0, 3.0], $rule->validQuantitiesUpTo(3));
    }

    public function testValuesNoShopCouldHaveSavedReadAsTheDefault(): void
    {
        $rule = QuantityRuleDTO::fromArray(['minQuantity' => 0, 'quantityStep' => -2, 'quantityDecimals' => 9, 'unitCode' => '']);

        self::assertSame(1.0, $rule->minQuantity);
        self::assertSame(1.0, $rule->step);
        self::assertSame(3, $rule->decimals);
        self::assertNull($rule->unitCode);
    }

    public function testALotOfSixWithAMinimumOfTwoStartsAtSixAndListsItsMultiples(): void
    {
        $rule = QuantityRuleDTO::fromArray(['minQuantity' => 2, 'quantityStep' => 6, 'quantityDecimals' => 0]);

        self::assertSame(6.0, $rule->smallestQuantity());
        self::assertTrue($rule->offersAList());
        // A stock left of 20 holds three lots, and the list stops at the last one.
        self::assertSame([6.0, 12.0, 18.0], $rule->validQuantitiesUpTo(20));
        self::assertSame(18.0, $rule->largestValidQuantityUpTo(20));
        self::assertNull($rule->largestValidQuantityUpTo(5), 'below the smallest lot nothing can be bought');
        self::assertSame([], $rule->validQuantitiesUpTo(5));
    }

    public function testAnUnboundedListStopsAtItsLength(): void
    {
        $rule = QuantityRuleDTO::fromArray(['quantityStep' => 6]);

        $quantities = $rule->validQuantitiesUpTo(\PHP_INT_MAX);

        self::assertCount(QuantityRuleDTO::LIST_LENGTH, $quantities);
        self::assertSame(6.0 * QuantityRuleDTO::LIST_LENGTH, end($quantities));
    }

    public function testAWeightTakesItsDecimalsWithoutAFloatDrift(): void
    {
        $rule = QuantityRuleDTO::fromArray(['minQuantity' => '0.1', 'quantityStep' => '0.1', 'quantityDecimals' => 1, 'unitCode' => 'kg']);

        self::assertTrue($rule->isSoldByMeasure());
        self::assertFalse($rule->offersAList());
        self::assertSame('0.1', $rule->inputStep());
        self::assertSame([0.1, 0.2, 0.3], $rule->validQuantitiesUpTo(0.3));
        self::assertSame(0.3, $rule->largestValidQuantityUpTo(0.35));
        self::assertSame('1.3', $rule->inputValue(1.25 + 0.05));
        self::assertSame('kg', $rule->unitCode);
    }

    public function testTheRuleRoundTripsThroughTheArrayALivePropHolds(): void
    {
        $rule = new QuantityRuleDTO(2.0, 0.25, 2, 0.75, 1.0, 'l');

        self::assertEquals($rule, QuantityRuleDTO::fromArray($rule->toArray()));
    }

    /**
     * @return iterable<string, array{float, float, int}>
     */
    public static function rules(): iterable
    {
        yield 'default' => [1, 1, 0];
        yield 'a minimum of two by six' => [2, 6, 0];
        yield 'a minimum of three by two' => [3, 2, 0];
        yield 'a quarter of a kilogram from one and a tenth' => [1.1, 0.25, 2];
        yield 'a gram from a third of a kilogram' => [0.333, 0.001, 3];
    }

    #[DataProvider('rules')]
    public function testTheSmallestQuantityIsTheOneOfTheCore(float $minimum, float $step, int $decimals): void
    {
        if (!class_exists(QuantityRule::class)) {
            self::markTestSkipped('This core has no quantity rules.');
        }

        self::assertSame(
            (new QuantityRule($minimum, $step, $decimals))->smallestQuantity(),
            (new QuantityRuleDTO($minimum, $step, $decimals))->smallestQuantity(),
        );
    }

    public function testASaleElementOfTheFrontApiCarriesItsRuleAndADecimalStock(): void
    {
        $saleElement = ProductSaleElementDTO::fromArray([
            'id' => 12,
            'quantity' => 37.5,
            'minQuantity' => 2,
            'quantityStep' => 6,
            'quantityDecimals' => 0,
            'unitQuantity' => 0.75,
            'referenceUnitQuantity' => 1,
            'unitCode' => 'l',
        ]);

        self::assertSame(37.5, $saleElement->quantity);
        self::assertSame(6.0, $saleElement->quantityRule->smallestQuantity());
        self::assertSame(0.75, $saleElement->quantityRule->unitQuantity);
        self::assertSame('l', $saleElement->quantityRule->unitCode);
        self::assertSame(1.0, (new ProductSaleElementDTO())->quantityRule->smallestQuantity());
    }
}
