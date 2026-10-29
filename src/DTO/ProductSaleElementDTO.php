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

namespace FlexyBundle\DTO;

class ProductSaleElementDTO
{
    public int $id = 0;
    public string $ref = '';
    /** The stock, in the unit the sale element is sold in: 7.5 for seven and a half kilograms. */
    public float $quantity = 0.0;
    public bool $promo = false;
    public bool $displayInitialPrice = true;
    public bool $newness = false;
    public float $weight = 0.0;
    public bool $isDefault = false;
    /** The next unit sold of it is sold below zero stock: the verdict of the core, which owns the rule. */
    public bool $isPreorder = false;

    /** Minimum, step, decimals and unit; the default rule on a core that sends none. */
    public QuantityRuleDTO $quantityRule;

    /** @var ProductPriceDTO[] */
    public array $productPrices = [];

    public function __construct()
    {
        $this->quantityRule = new QuantityRuleDTO();
    }

    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->id = isset($data['id']) ? (int) $data['id'] : 0;
        $dto->ref = isset($data['ref']) ? (string) $data['ref'] : '';
        $dto->quantity = isset($data['quantity']) ? (float) $data['quantity'] : 0.0;
        $dto->promo = (bool) ($data['promo'] ?? false);
        // Null means the catalog's own promo price stands, which is shown struck through.
        $dto->displayInitialPrice = (bool) ($data['displayInitialPrice'] ?? true);
        $dto->newness = (bool) ($data['newness'] ?? false);
        $dto->weight = isset($data['weight']) ? (float) $data['weight'] : 0.0;
        $dto->isDefault = (bool) ($data['isDefault'] ?? false);
        $dto->isPreorder = (bool) ($data['isPreorder'] ?? false);
        $dto->quantityRule = QuantityRuleDTO::fromArray($data);

        $dto->productPrices = array_values(
            array_map(
                static fn (array $row) => ProductPriceDTO::fromArray($row),
                (array) ($data['productPrices'] ?? [])
            )
        );

        return $dto;
    }
}
