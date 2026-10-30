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

class OrderProductDTO
{
    public int $productId = 0;
    public int $productSaleElementsId = 0;
    public string $productSaleElementsRef = '';
    public string $title = '';
    /** In the unit the line was sold in: 1.25 for a kilogram and a quarter. */
    public float $quantity = 0.0;
    /** Frozen when the order was placed; 0 on a line sold by the piece and on an older core. */
    public int $quantityDecimals = 0;
    public ?string $unitCode = null;
    public bool $wasInPromo = false;
    public float $price = 0.0;
    public float $promoPrice = 0.0;

    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->productId = isset($data['productId']) ? (int) $data['productId'] : 0;
        $dto->productSaleElementsId = isset($data['productSaleElementsId']) ? (int) $data['productSaleElementsId'] : 0;
        $dto->productSaleElementsRef = $data['productSaleElementsRef'] ?? '';
        $dto->title = (string) ($data['title'] ?? '');
        $dto->quantity = isset($data['quantity']) ? (float) $data['quantity'] : 0.0;
        $dto->quantityDecimals = (int) ($data['quantityDecimals'] ?? 0);
        $dto->unitCode = \is_string($data['unitCode'] ?? null) && '' !== $data['unitCode'] ? $data['unitCode'] : null;
        $dto->wasInPromo = isset($data['wasInPromo']) ? (bool) $data['wasInPromo'] : false;
        $dto->price = isset($data['price']) ? (float) $data['price'] : 0.0;
        $dto->promoPrice = isset($data['promoPrice']) ? (float) $data['promoPrice'] : 0.0;

        return $dto;
    }
}
