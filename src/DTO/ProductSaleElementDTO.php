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
    public int $quantity = 0;
    public bool $promo = false;
    public bool $displayInitialPrice = true;
    public bool $newness = false;
    public float $weight = 0.0;
    public bool $isDefault = false;

    /** @var ProductPriceDTO[] */
    public array $productPrices = [];

    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->id = isset($data['id']) ? (int) $data['id'] : 0;
        $dto->ref = isset($data['ref']) ? (string) $data['ref'] : '';
        $dto->quantity = isset($data['quantity']) ? (int) $data['quantity'] : 0;
        $dto->promo = (bool) ($data['promo'] ?? false);
        // Null means the catalog's own promo price stands, which is shown struck through.
        $dto->displayInitialPrice = (bool) ($data['displayInitialPrice'] ?? true);
        $dto->newness = (bool) ($data['newness'] ?? false);
        $dto->weight = isset($data['weight']) ? (float) $data['weight'] : 0.0;
        $dto->isDefault = (bool) ($data['isDefault'] ?? false);

        $dto->productPrices = array_values(
            array_map(
                static fn (array $row) => ProductPriceDTO::fromArray($row),
                (array) ($data['productPrices'] ?? [])
            )
        );

        return $dto;
    }
}
