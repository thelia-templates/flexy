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
use Symfony\UX\LiveComponent\LiveResponder;
use Thelia\Api\Service\DataAccess\AttributeAccessService;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Cart\Service\CartGiftWrappingService;
use Thelia\Domain\Cart\Service\CartItemService;
use Thelia\Domain\Cart\Service\CartRetriever;
use Thelia\Domain\Cart\Service\CartSelectionService;
use Thelia\Domain\Checkout\Service\GiftWrappingProvider;
use Thelia\Domain\Shipping\Service\PostageHandler;
use Thelia\Domain\Taxation\TaxEngine\TaxCalculatorFactoryInterface;
use Thelia\Model\Cart as CartModel;
use Thelia\Test\IntegrationTestCase;

/**
 * A line taken out of the cart is told to the page with the quantity it held (CheckoutEvents::BROWSER_REMOVE_PSE), through the
 * delete action, which also looks the image of the line up in the shop's database (Unit/CartBrowserEventsTest has the other
 * actions). Runs on an installed shop (bin/test-prepare).
 */
final class CartRemovalBrowserEventTest extends IntegrationTestCase
{
    public function testARemovedLineIsToldToThePageWithItsQuantity(): void
    {
        $responder = new LiveResponder();

        $this->cart($responder)->remove(0);

        self::assertSame(
            [['event' => CheckoutEvents::BROWSER_REMOVE_PSE, 'payload' => ['pse' => 7, 'quantity' => 3]]],
            $responder->getBrowserEventsToDispatch(),
        );
    }

    public function testAQuantityTypedDownToZeroTellsTheQuantityTheLineHad(): void
    {
        $responder = new LiveResponder();
        $cart = $this->cart($responder);
        // The data-model binding wrote 0 on the line; it held 3.
        $cart->items[0]->quantity = 0;

        $cart->onQuantityChanged(0, 3);

        self::assertSame(3, $cart->pendingDelete['quantity'] ?? null, 'the undo offer carries the quantity the line held');
        self::assertSame(
            [['event' => CheckoutEvents::BROWSER_REMOVE_PSE, 'payload' => ['pse' => 7, 'quantity' => 3]]],
            $responder->getBrowserEventsToDispatch(),
        );
    }

    private function cart(LiveResponder $responder): Cart
    {
        $cartRetriever = self::createStub(CartRetriever::class);
        $cartRetriever->method('fromSessionOrCreateNew')->willReturn(self::createStub(CartModel::class));

        $cart = new Cart(
            new CartFacade(
                self::createStub(CartItemService::class),
                self::createStub(CartSelectionService::class),
                self::createStub(PostageHandler::class),
                $cartRetriever,
                new CartGiftWrappingService(new GiftWrappingProvider(self::createStub(TaxCalculatorFactoryInterface::class))),
            ),
            self::createStub(FormServiceInterface::class),
            new CartStockService(),
            self::createStub(AttributeAccessService::class),
        );
        $cart->setLiveResponder($responder);
        $cart->items = [CartItemDto::fromArray([
            'id' => 100,
            'cartId' => 1,
            'productId' => 70,
            'productSaleElementsId' => 7,
            'quantity' => 3,
            'stock' => 5,
            'stockManaged' => true,
            'isOffered' => false,
            'title' => 'Casque',
        ])];

        return $cart;
    }
}
