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
use FlexyBundle\Event\CheckoutEvents;
use FlexyBundle\Service\CartStockService;
use Propel\Runtime\Propel;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\LiveResponder;
use Thelia\Api\Service\DataAccess\AttributeAccessService;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\CartItem;
use Thelia\Model\CartQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * A line taken out of the cart, or put back by the undo, is told to the page with its quantity
 * (CheckoutEvents::BROWSER_REMOVE_PSE and BROWSER_ADD_PSE). Both actions read the line stored in the cart of the session,
 * not the item the browser sends, so they run on a real cart (Unit/CartBrowserEventsTest has the other actions).
 * Runs on an installed shop (bin/test-prepare).
 */
final class CartRemovalBrowserEventTest extends IntegrationTestCase
{
    private ?FixtureFactory $factory = null;

    public function testARemovedLineIsToldToThePageWithItsQuantity(): void
    {
        $responder = new LiveResponder();
        [$cart, $line] = $this->cartWithALineOf(3, $responder);

        $cart->remove(0);

        self::assertSame([$this->removed($line, 3)], $responder->getBrowserEventsToDispatch());
    }

    public function testAQuantityTypedDownToZeroTellsTheQuantityTheLineHad(): void
    {
        $responder = new LiveResponder();
        [$cart, $line] = $this->cartWithALineOf(3, $responder);
        // The data-model binding wrote 0 on the line; it held 3.
        $cart->items[0]->quantity = 0;

        $cart->onQuantityChanged(0, 3);

        self::assertSame(3, $cart->pendingDelete['quantity'] ?? null, 'the undo offer carries the quantity the line held');
        self::assertSame([$this->removed($line, 3)], $responder->getBrowserEventsToDispatch());
    }

    public function testARestoredLineTellsTheQuantityBack(): void
    {
        $responder = new LiveResponder();
        [$cart, $line] = $this->cartWithALineOf(2, $responder);
        $cart->remove(0);

        $cart->restoreCartItem((int) $line->getProductSaleElementsId(), (int) $line->getProductId(), 2);

        self::assertSame(
            [$this->removed($line, 2), ['event' => CheckoutEvents::BROWSER_ADD_PSE, 'payload' => ['pse' => (int) $line->getProductSaleElementsId(), 'quantity' => 2]]],
            $responder->getBrowserEventsToDispatch(),
        );
    }

    /**
     * @return array{Cart, CartItem}
     */
    private function cartWithALineOf(int $quantity, LiveResponder $responder): array
    {
        $factory = $this->factory();
        $currency = $factory->currency();
        $product = $factory->product($factory->category(), $factory->taxRule(), $currency, ['basePrice' => 50.0, 'baseQuantity' => 10]);
        $cartModel = $factory->cart(null, ['currency' => $currency]);
        $line = $factory->cartItem($cartModel, $product, null, ['quantity' => (float) $quantity]);

        $stack = static::getContainer()->get('request_stack');
        \assert($stack instanceof RequestStack);
        $session = $stack->getCurrentRequest()?->getSession();
        \assert($session instanceof Session);
        $session->setSessionCart(CartQuery::create()->findPk($cartModel->getId()));

        $cart = new Cart(
            $this->getService(CartFacade::class),
            $this->getService(FormServiceInterface::class),
            $this->getService(CartStockService::class),
            $this->getService(AttributeAccessService::class),
            $this->getService(LangService::class),
        );
        $cart->setLiveResponder($responder);
        $cart->items = [CartItemDto::fromArray([
            'id' => $line->getId(),
            'cartId' => $cartModel->getId(),
            'productId' => $product->getId(),
            'productSaleElementsId' => $line->getProductSaleElementsId(),
            'quantity' => $quantity,
            'stock' => 10,
            'stockManaged' => true,
            'isOffered' => false,
            'title' => 'Casque',
        ])];

        return [$cart, $line];
    }

    /**
     * @return array{event: string, payload: array{pse: int, quantity: int}}
     */
    private function removed(CartItem $line, int $quantity): array
    {
        return ['event' => CheckoutEvents::BROWSER_REMOVE_PSE, 'payload' => ['pse' => (int) $line->getProductSaleElementsId(), 'quantity' => $quantity]];
    }

    private function factory(): FixtureFactory
    {
        return $this->factory ??= new FixtureFactory(Propel::getConnection('TheliaMain'));
    }
}
