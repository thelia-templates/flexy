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

class CartItemDto
{
    public int $id = 0;
    public int $cartId = 0;
    public int $productId = 0;
    public int $quantity = 0;
    public int $productSaleElementsId = 0;
    public float $price = 0.0;
    public float $promoPrice = 0.0;
    public int $promo = 0;
    /** The line was put in the cart by a promotion: the shop owns it, not the customer. */
    public bool $isOffered = false;
    /**
     * What the promotion owning this line takes off the cart because of it, bounded by what
     * the line is worth (a discount larger than the line takes the line), taxes included and
     * excluded. Zero on every line the shopper put there, and on an offered line the core has
     * not priced: a gift given at a percentage off carries less than the line is worth, one
     * given outright carries all of it. The core publishes these figures: the theme prints them.
     */
    public float $offeredEffectiveTaxed = 0.0;
    public float $offeredEffectiveUntaxed = 0.0;
    /** What the line is worth for its whole quantity at the price it is charged. */
    public float $lineTotalTaxed = 0.0;
    public float $lineTotalUntaxed = 0.0;
    /** The same once the effective discount is taken off: what the buyer pays for the line. */
    public float $lineTotalAfterDiscountTaxed = 0.0;
    public float $lineTotalAfterDiscountUntaxed = 0.0;
    public ?ProductDTO $product = null;
    public int $stock = 0;
    public bool $stockManaged = true;
    public string $title = '';
    public string $desc = '';

    public static function fromArray(array $data): self
    {
        $cartItem = new self();
        $cartItem->id = isset($data['id']) ? (int) $data['id'] : 0;
        $cartItem->cartId = isset($data['cartId']) ? (int) $data['cartId'] : 0;
        $cartItem->productId = isset($data['productId']) ? (int) $data['productId'] : 0;
        $cartItem->quantity = isset($data['quantity']) ? (int) $data['quantity'] : 0;
        $cartItem->productSaleElementsId = isset($data['productSaleElementsId']) ? (int) $data['productSaleElementsId'] : 0;
        $cartItem->price = isset($data['price']) ? (float) $data['price'] : 0.0;
        $cartItem->promoPrice = isset($data['promoPrice']) ? (float) $data['promoPrice'] : 0.0;
        $cartItem->promo = isset($data['promo']) ? (int) $data['promo'] : 0;
        // TINYINT column: the model hands it over as an int, and a core that predates
        // offered lines hands over nothing at all.
        $cartItem->isOffered = (bool) ($data['isOffered'] ?? false);
        // Not columns: the core publishes them alongside the line, in whichever spelling its
        // data access layer uses, and a core that predates them publishes nothing.
        $cartItem->offeredEffectiveTaxed = self::amount($data, 'offeredEffectiveTaxed', 'offered_effective_taxed');
        $cartItem->offeredEffectiveUntaxed = self::amount($data, 'offeredEffectiveUntaxed', 'offered_effective_untaxed');
        $cartItem->lineTotalTaxed = self::amount($data, 'lineTotalTaxed', 'line_total_taxed');
        $cartItem->lineTotalUntaxed = self::amount($data, 'lineTotalUntaxed', 'line_total_untaxed');
        $cartItem->lineTotalAfterDiscountTaxed = self::amount($data, 'lineTotalAfterDiscountTaxed', 'line_total_after_discount_taxed');
        $cartItem->lineTotalAfterDiscountUntaxed = self::amount($data, 'lineTotalAfterDiscountUntaxed', 'line_total_after_discount_untaxed');
        $cartItem->stock = isset($data['stock']) ? (int) $data['stock'] : 0;
        $cartItem->stockManaged = isset($data['stockManaged']) ? (bool) $data['stockManaged'] : true;
        $cartItem->title = $data['title'] ?? '';
        $cartItem->desc = $data['desc'] ?? '';

        if (isset($data['product'])) {
            $cartItem->product = $data['product'] instanceof ProductDTO
                ? $data['product']
                : ProductDTO::fromArray($data['product']);
        }

        return $cartItem;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function amount(array $data, string $camelKey, string $snakeKey): float
    {
        return (float) ($data[$camelKey] ?? $data[$snakeKey] ?? 0.0);
    }
}
