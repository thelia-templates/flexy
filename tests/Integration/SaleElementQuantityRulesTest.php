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

namespace FlexyBundle\Tests\Integration;

use FlexyBundle\DTO\QuantityRuleDTO;
use FlexyBundle\Service\SaleElementQuantityRules;
use Thelia\Domain\Catalog\Quantity\QuantityRule;
use Thelia\Test\IntegrationTestCase;

/**
 * The product sheet reads the rule of each of its sale elements in one query, through the
 * core's QuantityRule: a lot of six with a minimum of two, a weight with two decimals, and a
 * sale element nobody set a rule on, which sells one at a time.
 */
final class SaleElementQuantityRulesTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        if (!class_exists(QuantityRule::class)) {
            self::markTestSkipped('This core has no quantity rules.');
        }

        parent::setUp();
    }

    public function testEachSaleElementOfTheProductComesWithItsRule(): void
    {
        $factory = $this->createFixtureFactory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency());
        $lot = $factory->productSaleElement($product, ['minQuantity' => 2, 'quantityStep' => 6]);
        $weight = $factory->productSaleElement($product, ['quantityStep' => 0.25, 'quantityDecimals' => 2, 'unitQuantity' => 0.75, 'referenceUnitQuantity' => 1, 'unitCode' => 'kg']);
        $plain = $factory->productSaleElement($product);

        // Built by hand: the container inlines it into the product sheet, its one consumer.
        $rules = (new SaleElementQuantityRules())->ofProduct((int) $product->getId());

        foreach ([$lot, $weight, $plain] as $saleElement) {
            self::assertArrayHasKey((int) $saleElement->getId(), $rules);
        }

        self::assertSame(6.0, QuantityRuleDTO::fromArray($rules[(int) $lot->getId()])->smallestQuantity());
        self::assertSame(
            ['minQuantity' => 1.0, 'quantityStep' => 0.25, 'quantityDecimals' => 2, 'unitQuantity' => 0.75, 'referenceUnitQuantity' => 1.0, 'unitCode' => 'kg'],
            $rules[(int) $weight->getId()],
        );
        self::assertEquals(new QuantityRuleDTO(), QuantityRuleDTO::fromArray($rules[(int) $plain->getId()]));
    }

    public function testAProductThatDoesNotExistHasNoRule(): void
    {
        $reader = new SaleElementQuantityRules();

        self::assertSame([], $reader->ofProduct(0));
        self::assertSame([], $reader->ofProduct(\PHP_INT_MAX));
    }
}
