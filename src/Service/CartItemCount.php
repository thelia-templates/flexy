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

use Thelia\Domain\Cart\CartFacade;
use Thelia\Model\Cart;
use Thelia\Model\CartItemQuery;

/**
 * The "N items" of the cart button and the summaries: the sum of the quantities, as it always
 * was, until a line is sold by measure. 1.25 kg and two bottles are no "3.25 items": the cart
 * then counts its lines. The lines are read with their sale element in one query, the button
 * being on every page.
 */
final readonly class CartItemCount
{
    public function __construct(private CartFacade $cartFacade)
    {
    }

    public function ofTheSessionCart(): int
    {
        return $this->of($this->cartFacade->getCartFromSession());
    }

    public function of(?Cart $cart): int
    {
        if (null === $cart || null === $cart->getId()) {
            return 0;
        }

        $quantities = [];

        foreach (CartItemQuery::create()->filterByCartId($cart->getId())->joinWithProductSaleElements()->find() as $line) {
            $saleElement = $line->getProductSaleElements();
            // A core without quantity rules has no decimals column: every line is sold by the piece.
            $decimals = null !== $saleElement && method_exists($saleElement, 'getQuantityDecimals') ? (int) $saleElement->getQuantityDecimals() : 0;
            $quantities[] = ['quantity' => (float) $line->getQuantity(), 'decimals' => $decimals];
        }

        return self::count($quantities);
    }

    /**
     * @param iterable<array{quantity: float, decimals: int}> $lines
     */
    public static function count(iterable $lines): int
    {
        $lineCount = 0;
        $quantities = 0.0;
        $byLine = false;

        foreach ($lines as $line) {
            ++$lineCount;
            $quantities += $line['quantity'];
            $byLine = $byLine || $line['decimals'] > 0 || floor($line['quantity']) !== $line['quantity'];
        }

        return $byLine ? $lineCount : (int) round($quantities);
    }
}
