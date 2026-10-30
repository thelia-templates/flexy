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

use FlexyBundle\DTO\QuantityRuleDTO;
use FlexyBundle\Service\ReferenceUnitPrices;
use PHPUnit\Framework\TestCase;
use Thelia\Domain\Catalog\Quantity\ReferenceUnitPriceCalculator;

/**
 * A bottle of 75 cl at 9,99 € costs 13,32 € a litre; a bag of 250 g at 3 € costs 1,20 € per
 * hundred grams. A sale element that says nothing of its units has no reference price.
 */
final class ReferenceUnitPricesTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(ReferenceUnitPriceCalculator::class)) {
            self::markTestSkipped('This core has no reference unit prices.');
        }
    }

    public function testABottleOfSeventyFiveCentilitresIsPricedPerLitre(): void
    {
        $rule = new QuantityRuleDTO(unitQuantity: 0.75, referenceUnitQuantity: 1.0, unitCode: 'l');

        self::assertSame(['price' => 13.32, 'per' => 'l'], (new ReferenceUnitPrices())->of(9.99, $rule, 'fr_FR'));
    }

    public function testAReferenceOfAHundredGramsSaysSo(): void
    {
        $rule = new QuantityRuleDTO(unitQuantity: 250.0, referenceUnitQuantity: 100.0, unitCode: 'g');

        self::assertSame(['price' => 1.2, 'per' => '100 g'], (new ReferenceUnitPrices())->of(3.0, $rule, 'fr_FR'));
    }

    public function testNothingIsShownWithoutUnits(): void
    {
        $prices = new ReferenceUnitPrices();

        self::assertNull($prices->of(9.99, new QuantityRuleDTO(), 'fr_FR'));
        self::assertNull($prices->of(9.99, new QuantityRuleDTO(unitCode: 'l'), 'fr_FR'), 'a unit, but not what one bottle holds');
        self::assertNull($prices->of(9.99, new QuantityRuleDTO(unitQuantity: 0.75, referenceUnitQuantity: 1.0, unitCode: 'furlong'), 'fr_FR'), 'a unit this core does not know');
        self::assertNull($prices->of(null, new QuantityRuleDTO(unitQuantity: 0.75, referenceUnitQuantity: 1.0, unitCode: 'l'), 'fr_FR'), 'no price shown');
        self::assertNull($prices->of(9.99, null, 'fr_FR'));
    }
}
