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
use Thelia\Model\Cart;
use Thelia\Model\CartItem;
use Thelia\Model\ConfigQuery;
use Thelia\Model\ProductSaleElements;

/**
 * The core only re-checks stock when the order changes status, i.e. once the order row already
 * exists, and it reports the shortage as a raw developer string (`Thelia\Action\Order`). Reading
 * the same rule up front lets the checkout stop before that point.
 */
final readonly class CartStockService
{
    /**
     * Optional: a core without the bundles has no such service, and has no bundle line either.
     */
    public function __construct(
        private ?CartLineAvailability $lineAvailability = null,
    ) {
    }

    public function isStockManaged(ProductSaleElements $pse): bool
    {
        return ConfigQuery::checkAvailableStock() && 0 === $pse->getProduct()->getVirtual();
    }

    /**
     * How many units of a line the shop can supply, and whether it enforces that number.
     *
     * A line of a bundle is supplied by its components, never by the stock of its own sale element
     * (which stays at zero and would read as « out of stock » on every bundle): the core answers for it
     * (CartLineAvailability), from the composition the line holds. A bundle of virtual components only
     * has no limit to enforce.
     *
     * @return array{stock: int, managed: bool}
     */
    public function stockOf(CartItem $line, ProductSaleElements $pse): array
    {
        return $this->stocksOf([[$line, $pse]])[0];
    }

    /**
     * The same answer for many lines, the bundle lines of the cart read in one pass by the core
     * (a composition shared by two lines is asked once, a cart without bundle asks nothing).
     *
     * @param array<int|string, array{0: CartItem, 1: ProductSaleElements}> $lines the line and its sale element, by a key of the caller's
     *
     * @return array<int|string, array{stock: int, managed: bool}> by the same keys
     */
    public function stocksOf(array $lines): array
    {
        $answers = [];
        $bundleLines = [];

        foreach ($lines as $key => [$line, $pse]) {
            if (!method_exists($line, 'getBundleCompositionValue')) {
                $answers[$key] = $this->stockFor($pse, null);

                continue;
            }

            try {
                $composition = $line->getBundleCompositionValue();
            } catch (InvalidBundleCompositionException) {
                // A composition nobody can read is a line nobody can fill.
                $answers[$key] = ['stock' => 0, 'managed' => true];

                continue;
            }

            if (null === $composition || null === $this->lineAvailability) {
                $answers[$key] = $this->stockFor($pse, null);

                continue;
            }

            $bundleLines[$key] = [$pse, $composition];
        }

        if ([] !== $bundleLines) {
            foreach ($this->lineAvailability->forBundleLines($bundleLines) as $key => $available) {
                $answers[$key] = $this->answerFor($lines[$key][1], $available);
            }
        }

        return $answers;
    }

    /**
     * The same answer for a composition not on a line yet (a line being put back in the cart).
     *
     * @return array{stock: int, managed: bool}
     */
    public function stockFor(ProductSaleElements $pse, ?BundleComposition $composition): array
    {
        $managed = $this->isStockManaged($pse);

        if (null === $this->lineAvailability || null === $composition) {
            return ['stock' => (int) $pse->getQuantity(), 'managed' => $managed];
        }

        return $this->answerFor($pse, $this->lineAvailability->forLine($pse, $composition));
    }

    /**
     * @return array{stock: int, managed: bool}
     */
    private function answerFor(ProductSaleElements $pse, float $available): array
    {
        if ($available >= CartLineAvailability::UNLIMITED) {
            return ['stock' => \PHP_INT_MAX, 'managed' => false];
        }

        return ['stock' => (int) floor($available), 'managed' => $this->isStockManaged($pse)];
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

        foreach ($this->stocksOf($lines) as $id => ['stock' => $stock, 'managed' => $managed]) {
            if ($managed && $lines[$id][0]->getQuantity() > $stock) {
                return true;
            }
        }

        return false;
    }
}
