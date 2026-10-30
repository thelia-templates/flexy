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

use FlexyBundle\DTO\CartItemDto;
use Thelia\Domain\Cart\Bundle\CartLineAvailability;
use Thelia\Domain\Catalog\Bundle\BundleComposition;
use Thelia\Domain\Catalog\Bundle\Exception\InvalidBundleCompositionException;
use Thelia\Domain\Catalog\Stock\SellableStock;
use Thelia\Model\Cart;
use Thelia\Model\CartItem;
use Thelia\Model\ProductSaleElements;

/**
 * What the shop may still sell of a line is the answer of the core (the stock and what the sale mode allows
 * below zero, less what other carts reserve): the theme holds no stock rule of its own, it reads the answer.
 *
 * The core only re-checks stock when the order changes status, i.e. once the order row already
 * exists, and it reports the shortage as a raw developer string (`Thelia\Action\Order`). Reading
 * the same answer up front lets the checkout stop before that point.
 */
final readonly class CartStockService
{
    /**
     * Optional: a core without the bundles has no line service, and has no bundle line either.
     */
    public function __construct(
        private ?CartLineAvailability $lineAvailability = null,
        private ?SellableStock $sellableStock = null,
    ) {
    }

    /**
     * @return array{stock: int|float, managed: bool, preorder: bool}
     */
    public function stockOf(CartItem $line, ProductSaleElements $pse): array
    {
        return $this->stocksOf([[$line, $pse]], $line->getCart())[0];
    }

    /**
     * What the shop can supply of each line, and whether it enforces that number, with the verdict of the
     * core on whether the line is sold below zero stock.
     *
     * The lines that are not a bundle are read in one pass for the whole cart (SellableStock::forCart: a
     * count of queries that does not grow with the lines). A line of a bundle is supplied by its components,
     * never by the stock of its own sale element (which stays at zero and would read as « out of stock » on
     * every bundle): the core answers for it (CartLineAvailability), from the composition the line holds, a
     * composition shared by two lines asked once. A bundle of virtual components only has no limit to enforce.
     *
     * @param array<int|string, array{0: CartItem, 1: ProductSaleElements}> $lines the line and its sale element, by a key of the caller's
     * @param Cart|null                                                     $cart  the cart the lines belong to, whose own reservation is not held against it
     *
     * @return array<int|string, array{stock: int|float, managed: bool, preorder: bool}> by the same keys
     */
    public function stocksOf(array $lines, ?Cart $cart = null): array
    {
        $answers = [];
        $bundleLines = [];
        $plainLines = [];

        foreach ($lines as $key => [$line, $pse]) {
            if (!method_exists($line, 'getBundleCompositionValue')) {
                $plainLines[$key] = [$line, $pse];

                continue;
            }

            try {
                $composition = $line->getBundleCompositionValue();
            } catch (InvalidBundleCompositionException) {
                // A composition nobody can read is a line nobody can fill.
                $answers[$key] = ['stock' => 0, 'managed' => true, 'preorder' => false];

                continue;
            }

            if (null === $composition || null === $this->lineAvailability) {
                $plainLines[$key] = [$line, $pse];

                continue;
            }

            $bundleLines[$key] = [$pse, $composition];
        }

        if ([] !== $bundleLines) {
            foreach ($this->lineAvailability?->forBundleLines($bundleLines) ?? [] as $key => $available) {
                // A bundle is sold whole: the half of one its components could fill is none.
                $answers[$key] = $this->answerFor(floor($available), false);
            }
        }

        if ([] !== $plainLines) {
            $cart ??= $plainLines[array_key_first($plainLines)][0]->getCart();
            // Absent from the answer: nothing to enforce (the shop does not check the stock).
            // The sale elements of the lines are read already: the core does not read them again.
            $read = $this->sellableStock?->forCart($cart, $this->saleElementsOf($plainLines)) ?? [];

            foreach ($plainLines as $key => [$line, $pse]) {
                $answer = $read[(int) $line->getId()] ?? null;
                $answers[$key] = null === $answer
                    ? $this->answerFor(CartLineAvailability::UNLIMITED, false)
                    : $this->answerFor($answer['available'] ?? CartLineAvailability::UNLIMITED, $answer['preorder']);
            }
        }

        return $answers;
    }

    /**
     * @param array<int|string, array{0: CartItem, 1: ProductSaleElements}> $lines
     *
     * @return array<int, ProductSaleElements> by id
     */
    private function saleElementsOf(array $lines): array
    {
        $saleElements = [];

        foreach ($lines as [, $pse]) {
            $saleElements[(int) $pse->getId()] = $pse;
        }

        return $saleElements;
    }

    /**
     * The same answer for a composition not on a line yet (a line being put back in the cart).
     *
     * @param Cart|null $cart the cart asking, whose own reservation is not held against it
     *
     * @return array{stock: int|float, managed: bool, preorder: bool}
     */
    public function stockFor(ProductSaleElements $pse, ?BundleComposition $composition, ?Cart $cart = null): array
    {
        if (null === $this->lineAvailability) {
            return $this->answerFor(CartLineAvailability::UNLIMITED, false);
        }

        $available = $this->lineAvailability->forLine($pse, $composition, $cart);

        // A bundle is sold whole; the stock of a sale element sold by measure keeps its decimals (2.5 kg is not 2).
        return $this->answerFor(null === $composition ? $available : floor($available), false);
    }

    /**
     * @return array{stock: int|float, managed: bool, preorder: bool}
     */
    private function answerFor(float $available, bool $preorder): array
    {
        if ($available >= CartLineAvailability::UNLIMITED) {
            return ['stock' => \PHP_INT_MAX, 'managed' => false, 'preorder' => $preorder];
        }

        // In the unit the line is sold in, rounded at the micro unit, the precision of the columns:
        // a whole number when it is one, the decimals kept otherwise (2.5 kg is not 2).
        $available = round($available, 6);

        return ['stock' => floor($available) === $available ? (int) $available : $available, 'managed' => true, 'preorder' => $preorder];
    }

    /**
     * Nothing left: the line cannot be ordered at all.
     */
    public function isOutOfStock(CartItemDto $item): bool
    {
        return $item->stockManaged && $item->stock <= 0;
    }

    /**
     * Some left, but fewer than the line asks for: the quantity has to come down.
     */
    public function isInsufficient(CartItemDto $item): bool
    {
        return $item->stockManaged && $item->stock > 0 && $item->quantity > $item->stock;
    }

    public function hasInsufficientStock(Cart $cart): bool
    {
        $lines = [];
        foreach ($cart->getCartItems() as $cartItem) {
            $pse = $cartItem->getProductSaleElements();

            if (null !== $pse) {
                $lines[(int) $cartItem->getId()] = [$cartItem, $pse];
            }
        }

        foreach ($this->stocksOf($lines, $cart) as $id => ['stock' => $stock, 'managed' => $managed]) {
            if ($managed && $lines[$id][0]->getQuantity() > $stock) {
                return true;
            }
        }

        return false;
    }
}
