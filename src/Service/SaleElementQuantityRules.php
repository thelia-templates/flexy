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
use Thelia\Domain\Catalog\Quantity\QuantityRule;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * The quantity rule of each sale element of a product, for the product sheet. The sheet reads
 * its sale elements from ProductSaleElementsAccessService::psesByProduct(), whose payload has
 * no rule in it: one query here completes it, read through the core's own QuantityRule so the
 * columns are interpreted once, the way the cart interprets them.
 *
 * A core without quantity rules has no QuantityRule class: nothing is read, and every sale
 * element keeps the default rule (one at a time, no decimals).
 */
final readonly class SaleElementQuantityRules
{
    /**
     * @return array<int, array{minQuantity: float, quantityStep: float, quantityDecimals: int, unitQuantity: float|null, referenceUnitQuantity: float|null, unitCode: string|null}> by sale element id
     */
    public function ofProduct(int $productId): array
    {
        if ($productId <= 0 || !class_exists(QuantityRule::class)) {
            return [];
        }

        $rules = [];

        foreach (ProductSaleElementsQuery::create()->filterByProductId($productId)->find() as $saleElement) {
            try {
                $rule = QuantityRule::fromProductSaleElements($saleElement);
            } catch (InvalidQuantityRuleException) {
                // A rule the back office would not let anyone save: the cart refuses its
                // quantities anyway, and the selector falls back to the default.
                continue;
            }

            $rules[(int) $saleElement->getId()] = (new QuantityRuleDTO(
                minQuantity: $rule->minQuantity,
                step: $rule->step,
                decimals: $rule->decimals,
                unitQuantity: $rule->unitQuantity,
                referenceUnitQuantity: $rule->referenceUnitQuantity,
                unitCode: $rule->unitCode?->value,
            ))->toArray();
        }

        return $rules;
    }
}
