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
use FlexyBundle\Event\CheckoutEvents;
use FlexyBundle\Service\CartStockService;
use Propel\Runtime\Propel;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\LiveResponder;
use Thelia\Api\Service\DataAccess\AttributeAccessService;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Cart\Exception\InvalidCartException;
use Thelia\Domain\Cart\Exception\NotEnoughStockException;
use Thelia\Domain\Cart\Service\CartGiftWrappingService;
use Thelia\Domain\Cart\Service\CartItemService;
use Thelia\Domain\Cart\Service\CartRetriever;
use Thelia\Domain\Cart\Service\CartSelectionService;
use Thelia\Domain\Checkout\Service\GiftWrappingProvider;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Shipping\Service\PostageHandler;
use Thelia\Domain\Taxation\TaxEngine\TaxCalculatorFactoryInterface;
use Thelia\Model\CartItem;
use Thelia\Model\CartQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * A restore the cart refuses (a module's CART_ADDITEM listener throwing InvalidCartException, the stock gone
 * between the check and the add) tells the shopper the line was not put back, where the live request used to
 * answer 500; an accepted restore still emits the cart event. The cart item service is stubbed to give the
 * refusal, the removal it follows is a real one (a signed pendingDelete on a fixture product that has stock).
 * Runs on an installed shop (bin/test-prepare).
 */
final class CartRestoreRefusalTest extends IntegrationTestCase
{
    public function testARestoreAModuleRefusesSaysTheLineWasNotPutBack(): void
    {
        $responder = new LiveResponder();
        [$component, $product] = $this->componentFor(new InvalidCartException('Sold by 6'), $responder);

        $component->restoreCartItem($this->pseId($product), $product->getId(), 1);

        self::assertSame('restore', $component->refusal);
        self::assertSame('Sold by 6', $component->refusalReason);
        self::assertNotNull($component->pendingDelete, 'the undo stays offered');
        self::assertSame([], $responder->getEventsToEmit());
    }

    public function testARestoreRefusedForTheStockSaysTheLineWasNotPutBack(): void
    {
        $responder = new LiveResponder();
        [$component, $product] = $this->componentFor(new NotEnoughStockException('Not enough stock for product REF-1'), $responder);

        $component->restoreCartItem($this->pseId($product), $product->getId(), 1);

        self::assertSame('restore', $component->refusal);
        self::assertSame('', $component->refusalReason);
        self::assertSame([], $responder->getEventsToEmit());
    }

    public function testAnAcceptedRestoreStillEmitsTheCartEvent(): void
    {
        $responder = new LiveResponder();
        [$component, $product] = $this->componentFor(null, $responder);

        $component->restoreCartItem($this->pseId($product), $product->getId(), 1);

        self::assertNull($component->refusal);
        self::assertNull($component->pendingDelete);
        self::assertSame([CheckoutEvents::ADD_ITEM_EVENT], array_column($responder->getEventsToEmit(), 'event'));
    }

    /**
     * @return array{Cart, \Thelia\Model\Product}
     */
    private function componentFor(?\Throwable $refusal, LiveResponder $responder): array
    {
        $factory = new FixtureFactory(Propel::getConnection('TheliaMain'));
        $currency = $factory->currency();
        $product = $factory->product($factory->category(), $factory->taxRule(), $currency, ['basePrice' => 50.0, 'baseQuantity' => 10]);
        $cart = $factory->cart(null, ['currency' => $currency]);

        $stack = static::getContainer()->get('request_stack');
        \assert($stack instanceof RequestStack);
        $session = $stack->getCurrentRequest()?->getSession();
        \assert($session instanceof Session);
        $session->setSessionCart(CartQuery::create()->findPk($cart->getId()));

        $cartItems = self::createStub(CartItemService::class);

        if (null === $refusal) {
            $cartItems->method('addItem')->willReturn(self::createStub(CartItem::class));
        } else {
            $cartItems->method('addItem')->willThrowException($refusal);
        }

        $facade = new CartFacade(
            $cartItems,
            self::createStub(CartSelectionService::class),
            self::createStub(PostageHandler::class),
            $this->getService(CartRetriever::class),
            new CartGiftWrappingService(new GiftWrappingProvider(self::createStub(TaxCalculatorFactoryInterface::class))),
        );

        $component = new Cart(
            $facade,
            $this->getService(FormServiceInterface::class),
            $this->getService(CartStockService::class),
            $this->getService(AttributeAccessService::class),
            $this->getService(LangService::class),
        );
        $component->setLiveResponder($responder);
        $component->pendingDelete = [
            'title' => 'Removed',
            'productId' => $product->getId(),
            'pseId' => $this->pseId($product),
            'quantity' => 1,
            'imageId' => null,
        ];

        return [$component, $product];
    }

    private function pseId(\Thelia\Model\Product $product): int
    {
        return (int) $product->getProductSaleElementss()->getFirst()->getId();
    }
}
