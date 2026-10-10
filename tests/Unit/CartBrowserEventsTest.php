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

namespace FlexyBundle\Tests\Unit;

use FlexyBundle\Components\Organisms\Cart\Base as Cart;
use FlexyBundle\DTO\CartItemDto;
use FlexyBundle\Event\CheckoutEvents;
use FlexyBundle\Service\CartStockService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\Forms;
use Symfony\UX\LiveComponent\LiveResponder;
use Thelia\Api\Service\DataAccess\AttributeAccessService;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Cart\Exception\NotEnoughStockException;
use Thelia\Domain\Cart\Service\CartGiftWrappingService;
use Thelia\Domain\Cart\Service\CartItemService;
use Thelia\Domain\Cart\Service\CartRetriever;
use Thelia\Domain\Cart\Service\CartSelectionService;
use Thelia\Domain\Checkout\Service\GiftWrappingProvider;
use Thelia\Domain\Shipping\Service\PostageHandler;
use Thelia\Domain\Taxation\TaxEngine\TaxCalculatorFactoryInterface;
use Thelia\Model\Cart as CartModel;
use Thelia\Model\CartItem;

/**
 * What the cart tells the page, as a DOM event, once a change went through (CheckoutEvents::BROWSER_ADD_PSE and
 * BROWSER_REMOVE_PSE): the quantity a line gained or lost, a change of nothing told to nobody. The line is the one at index 0:
 * product sale element 7, quantity 2, stock 5.
 *
 * The cart is a generated Propel model: the test is skipped where those are not on the autoload.
 */
final class CartBrowserEventsTest extends TestCase
{
    protected function setUp(): void
    {
        try {
            class_exists(CartModel::class);
        } catch (\Error) {
            self::markTestSkipped('The generated Propel models are not autoloaded.');
        }
    }

    public function testAPlusTellsTheQuantityAdded(): void
    {
        $responder = new LiveResponder();

        $this->cart($responder)->plus(0);

        self::assertSame([$this->added(7, 1)], $responder->getBrowserEventsToDispatch());
    }

    public function testAMinusTellsTheQuantityRemoved(): void
    {
        $responder = new LiveResponder();

        $this->cart($responder)->minus(0);

        self::assertSame([$this->removed(7, 1)], $responder->getBrowserEventsToDispatch());
    }

    public function testAPlusStoppedByTheStockTellsNothing(): void
    {
        $responder = new LiveResponder();
        $cart = $this->cart($responder, quantity: 5);

        $cart->plus(0);

        self::assertSame([], $responder->getBrowserEventsToDispatch(), 'the line is at its stock: it gained nothing');
    }

    public function testTypingAQuantityTellsTheDifferenceWithTheQuantityTheLineHad(): void
    {
        $responder = new LiveResponder();
        $cart = $this->cart($responder);
        // The data-model binding has written the typed quantity (4) on the line; the line had 2.
        $cart->items[0]->quantity = 4;

        $cart->onQuantityChanged(0, 2);

        self::assertSame([$this->added(7, 2)], $responder->getBrowserEventsToDispatch());
    }

    public function testTypingALowerQuantityTellsTheQuantityRemoved(): void
    {
        $responder = new LiveResponder();
        $cart = $this->cart($responder, quantity: 4);
        $cart->items[0]->quantity = 1;

        $cart->onQuantityChanged(0, 4);

        self::assertSame([$this->removed(7, 3)], $responder->getBrowserEventsToDispatch());
    }

    public function testARestoredLineTellsTheQuantityBack(): void
    {
        $responder = new LiveResponder();

        $this->cart($responder)->restoreCartItem(7, 70, 2);

        self::assertSame([$this->added(7, 2)], $responder->getBrowserEventsToDispatch());
    }

    public function testAChangeTheCartRefusesTellsNothing(): void
    {
        $responder = new LiveResponder();
        $cart = $this->cart($responder, refusal: new NotEnoughStockException());

        foreach (['plus', 'minus'] as $action) {
            try {
                $cart->{$action}(0);
                self::fail('The refusal of the cart is not swallowed.');
            } catch (NotEnoughStockException) {
            }
        }

        self::assertSame([], $responder->getBrowserEventsToDispatch(), 'the cart refused: nothing changed, nothing is told');
    }

    public function testALineLeftAloneBecauseItIsOfferedTellsNothing(): void
    {
        $responder = new LiveResponder();
        $cart = $this->cart($responder);
        $cart->items[0]->isOffered = true;

        $cart->plus(0);
        $cart->minus(0);

        self::assertSame([], $responder->getBrowserEventsToDispatch());
    }

    /**
     * @return array{event: string, payload: array{pse: int, quantity: int}}
     */
    private function added(int $pse, int $quantity): array
    {
        return ['event' => CheckoutEvents::BROWSER_ADD_PSE, 'payload' => ['pse' => $pse, 'quantity' => $quantity]];
    }

    /**
     * @return array{event: string, payload: array{pse: int, quantity: int}}
     */
    private function removed(int $pse, int $quantity): array
    {
        return ['event' => CheckoutEvents::BROWSER_REMOVE_PSE, 'payload' => ['pse' => $pse, 'quantity' => $quantity]];
    }

    private function cart(LiveResponder $responder, int $quantity = 2, ?\Throwable $refusal = null): Cart
    {
        $cartRetriever = self::createStub(CartRetriever::class);
        $cartRetriever->method('fromSessionOrCreateNew')->willReturn(self::createStub(CartModel::class));
        $cartItems = self::createStub(CartItemService::class);

        if (null === $refusal) {
            $cartItems->method('updateQuantityItem')->willReturn(self::createStub(CartItem::class));
        } else {
            $cartItems->method('updateQuantityItem')->willThrowException($refusal);
        }

        $cartItems->method('addItem')->willReturn(self::createStub(CartItem::class));

        $formService = self::createStub(FormServiceInterface::class);
        $formService->method('getFormByName')->willReturnCallback(static function (): Form {
            $builder = Forms::createFormFactory()->createNamedBuilder('thelia_cart_add', FormType::class);

            foreach (['product', 'product_sale_elements_id', 'quantity', 'append', 'newness'] as $field) {
                $builder->add($field, TextType::class);
            }

            $form = $builder->getForm();
            self::assertInstanceOf(Form::class, $form);

            return $form;
        });

        $cart = new Cart(
            new CartFacade(
                $cartItems,
                self::createStub(CartSelectionService::class),
                self::createStub(PostageHandler::class),
                $cartRetriever,
                new CartGiftWrappingService(new GiftWrappingProvider(self::createStub(TaxCalculatorFactoryInterface::class))),
            ),
            $formService,
            new CartStockService(),
            self::createStub(AttributeAccessService::class),
        );
        $cart->setLiveResponder($responder);
        $cart->items = [CartItemDto::fromArray([
            'id' => 100,
            'cartId' => 1,
            'productId' => 70,
            'productSaleElementsId' => 7,
            'quantity' => $quantity,
            'stock' => 5,
            'stockManaged' => true,
            'isOffered' => false,
            'title' => 'Casque',
        ])];

        return $cart;
    }
}
