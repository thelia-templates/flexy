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

namespace FlexyBundle\Tests\Integration;

use FlexyBundle\Components\Organisms\Cart\Base as Cart;
use FlexyBundle\DTO\CartItemDto;
use FlexyBundle\Service\CartStockService;
use Propel\Runtime\Propel;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\LiveResponder;
use Thelia\Api\Service\DataAccess\AttributeAccessService;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Model\CartItemQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CartQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The undo of a removal on the cart does not put back more than the component removed, nor a line the stock cannot
 * give: its arguments come from the browser, and the cart form that holds the stock rule is only replayed there.
 * Runs on an installed shop (bin/test-prepare).
 */
final class CartRestoreStockTest extends IntegrationTestCase
{
    private ?FixtureFactory $factory = null;

    public function testAnUndoOfALineWithNoStockLeftAddsNothing(): void
    {
        [$cart, $product] = $this->cartAndProduct(0);
        $component = $this->component($product, 1);

        $component->restoreCartItem($this->pseId($product), $product->getId(), 1);

        self::assertSame(0.0, $this->quantityIn($cart));
        self::assertNull($component->pendingDelete, 'the offer to undo is gone');
    }

    public function testAnUndoPutsBackTheRemovedQuantityAndNoMore(): void
    {
        [$cart, $product] = $this->cartAndProduct(10);
        $component = $this->component($product, 2);

        $component->restoreCartItem($this->pseId($product), $product->getId(), 9);

        self::assertSame(2.0, $this->quantityIn($cart), 'the quantity argument is bounded by the removed line');
        self::assertNull($component->pendingDelete);
    }

    public function testAnUndoOfAnotherDeclinationThanTheRemovedOneAddsNothing(): void
    {
        [$cart, $product] = $this->cartAndProduct(10);
        $other = $this->factory()->product($this->factory()->category(), $this->factory()->taxRule(), $this->factory()->currency(), ['baseQuantity' => 10]);
        $component = $this->component($product, 2);

        $component->restoreCartItem($this->pseId($other), $other->getId(), 1);

        self::assertSame(0.0, $this->quantityIn($cart));
    }

    public function testAnUndoWithoutARemovalAddsNothing(): void
    {
        [$cart, $product] = $this->cartAndProduct(10);
        $component = $this->component($product, 2);
        $component->pendingDelete = null;

        $component->restoreCartItem($this->pseId($product), $product->getId(), 1);

        self::assertSame(0.0, $this->quantityIn($cart));
    }

    public function testAnUndoOfAnUnknownDeclinationIsRefused(): void
    {
        [$cart, $product] = $this->cartAndProduct(10);
        $component = $this->component($product, 2);
        $component->pendingDelete['pseId'] = 99999999;

        $component->restoreCartItem(99999999, $product->getId(), 1);

        self::assertSame(0.0, $this->quantityIn($cart));
        self::assertNull($component->pendingDelete);
    }

    public function testTheStockIsNotCheckedWhenTheShopDoesNotAskForIt(): void
    {
        [$cart, $product] = $this->cartAndProduct(0);
        ConfigQuery::write('check-available-stock', '0');
        $component = $this->component($product, 1);

        $component->restoreCartItem($this->pseId($product), $product->getId(), 1);

        self::assertSame(1.0, $this->quantityIn($cart));
    }

    public function testAVirtualProductHasNoStockToRunOutOf(): void
    {
        [$cart, $product] = $this->cartAndProduct(0);
        Propel::getConnection('TheliaMain')->exec(\sprintf('UPDATE product SET virtual = 1 WHERE id = %d', $product->getId()));
        $component = $this->component($product, 1);

        $component->restoreCartItem($this->pseId($product), $product->getId(), 1);

        self::assertSame(1.0, $this->quantityIn($cart));
    }

    public function testARemovalReadsTheLineStoredInTheCartNotTheItemOfTheBrowser(): void
    {
        [$cart, $product] = $this->cartAndProduct(10);
        $other = $this->factory()->product($this->factory()->category(), $this->factory()->taxRule(), $this->factory()->currency(), ['baseQuantity' => 0]);
        $line = $this->factory()->cartItem($cart, $product);
        $component = $this->component($product, 1);
        $component->pendingDelete = null;
        // A forged item: the id of the stored line, but another declination and a huge quantity.
        $forged = CartItemDto::fromArray(['id' => $line->getId(), 'productId' => $other->getId(), 'productSaleElementsId' => $this->pseId($other), 'quantity' => 500, 'title' => 'Forged']);
        $component->items = [$forged];

        $component->remove(0);
        self::assertSame($this->pseId($product), $component->pendingDelete['pseId'] ?? null, 'the declination of the stored line');
        self::assertSame(1, $component->pendingDelete['quantity'] ?? null, 'the quantity of the stored line');

        $component->restoreCartItem($this->pseId($other), $other->getId(), 500);
        self::assertSame(0.0, $this->quantityIn($cart), 'the forged declination is not put back');
    }

    /**
     * @return array{\Thelia\Model\Cart, \Thelia\Model\Product}
     */
    private function cartAndProduct(int $stock): array
    {
        $factory = $this->factory();
        $currency = $factory->currency();
        $product = $factory->product($factory->category(), $factory->taxRule(), $currency, ['basePrice' => 50.0, 'baseQuantity' => $stock]);
        $cart = $factory->cart(null, ['currency' => $currency]);

        $stack = static::getContainer()->get('request_stack');
        \assert($stack instanceof RequestStack);
        $session = $stack->getCurrentRequest()?->getSession();
        \assert($session instanceof Session);
        $session->setSessionCart(CartQuery::create()->findPk($cart->getId()));

        return [$cart, $product];
    }

    private function factory(): FixtureFactory
    {
        return $this->factory ??= new FixtureFactory(Propel::getConnection('TheliaMain'));
    }

    private function component(\Thelia\Model\Product $product, int $removedQuantity): Cart
    {
        $component = new Cart(
            $this->getService(CartFacade::class),
            $this->getService(FormServiceInterface::class),
            $this->getService(CartStockService::class),
            $this->getService(AttributeAccessService::class),
        );
        $component->setLiveResponder(new LiveResponder());
        $component->pendingDelete = [
            'title' => 'Removed',
            'productId' => $product->getId(),
            'pseId' => $this->pseId($product),
            'quantity' => $removedQuantity,
            'imageId' => null,
        ];

        return $component;
    }

    private function pseId(\Thelia\Model\Product $product): int
    {
        return (int) $product->getProductSaleElementss()->getFirst()->getId();
    }

    private function quantityIn(\Thelia\Model\Cart $cart): float
    {
        $quantity = 0.0;

        foreach (CartItemQuery::create()->filterByCartId($cart->getId())->find() as $line) {
            $quantity += (float) $line->getQuantity();
        }

        return $quantity;
    }
}
