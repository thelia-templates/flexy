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

class ProductPriceDTO
{
    public string $currency = '';
    public float $price = 0.0;
    public ?float $promoPrice = null;
    /**
     * The same two prices with their taxes, as the front API publishes them for the sale
     * element of a visitor: null where the API does not (a core that predates them).
     */
    public ?float $taxedPrice = null;
    public ?float $taxedPromoPrice = null;

    public static function fromArray(array $data): self
    {
        $dto = new self();

        if (is_string($data['currency'])) {
            $dto->currency = $data['currency'];
        } elseif (is_array($data['currency'])) {
            $dto->currency = $data['currency']['code'] ?? '';
        }

        $dto->price = isset($data['price']) ? (float) $data['price'] : 0.0;
        $dto->promoPrice = array_key_exists('promoPrice', $data) ? (float) $data['promoPrice'] : null;

        $dto->taxedPrice = isset($data['taxedPrice']) ? (float) $data['taxedPrice'] : null;
        $dto->taxedPromoPrice = isset($data['taxedPromoPrice']) ? (float) $data['taxedPromoPrice'] : null;

        return $dto;
    }
}
