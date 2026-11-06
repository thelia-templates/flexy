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

namespace FlexyBundle\Service;

use FlexyBundle\DTO\QuantityRuleDTO;
use Thelia\Domain\Catalog\Quantity\InvalidQuantityRuleException;
use Thelia\Domain\Catalog\Quantity\QuantityFormatter;
use Thelia\Domain\Catalog\Quantity\QuantityRule;
use Thelia\Domain\Catalog\Quantity\ReferenceUnitPriceCalculator;
use Thelia\Domain\Catalog\Quantity\UnitCode;

/**
 * The price of a sale element brought to its reference unit, from the price the page shows
 * next to it: taxes included, promotion included, as Flexy shows every price. The amount is the
 * core's (ReferenceUnitPriceCalculator); this only says what it is per: "l" for a litre,
 * "100 g" for a hundred grams.
 *
 * Nothing on a sale element that declares no units, and nothing on a core without quantity
 * rules: there the rule never carries one.
 */
final readonly class ReferenceUnitPrices
{
    /**
     * @return array{price: float, per: string}|null
     */
    public function of(?float $displayedPrice, ?QuantityRuleDTO $rule, ?string $locale = null): ?array
    {
        if (null === $displayedPrice || null === $rule || null === $rule->unitCode || !class_exists(ReferenceUnitPriceCalculator::class)) {
            return null;
        }

        try {
            $coreRule = new QuantityRule(
                minQuantity: $rule->minQuantity,
                step: $rule->step,
                decimals: $rule->decimals,
                unitQuantity: $rule->unitQuantity,
                referenceUnitQuantity: $rule->referenceUnitQuantity,
                unitCode: UnitCode::tryFrom($rule->unitCode),
            );
        } catch (InvalidQuantityRuleException) {
            return null;
        }

        $price = (new ReferenceUnitPriceCalculator())->compute($displayedPrice, $coreRule);

        if (null === $price) {
            return null;
        }

        return ['price' => $price->price, 'per' => $this->per($price->referenceUnitQuantity, $price->unitCode, $locale)];
    }

    /**
     * One reference unit is written as its symbol alone, "l"; any other quantity with its own
     * decimals and always its unit, "100 g", "0,5 kg", whatever the decimals the sale element
     * is sold with. A core that writes the unit of every quantity has no formatMeasure().
     */
    private function per(float $referenceUnitQuantity, UnitCode $unitCode, ?string $locale): string
    {
        $written = rtrim(rtrim(number_format($referenceUnitQuantity, 3, '.', ''), '0'), '.');

        if ('1' === $written) {
            return $unitCode->symbol();
        }

        $decimals = str_contains($written, '.') ? \strlen($written) - strpos($written, '.') - 1 : 0;

        $formatter = new QuantityFormatter();

        if (!method_exists($formatter, 'formatMeasure')) {
            return $formatter->format($referenceUnitQuantity, $decimals, $unitCode, $locale);
        }

        return $formatter->formatMeasure($referenceUnitQuantity, $decimals, $unitCode, $locale);
    }
}
