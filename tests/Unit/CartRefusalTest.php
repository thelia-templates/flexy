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
use Thelia\Domain\Cart\Exception\InvalidCartException;
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
 * A quantity or a restore the cart refuses (a module's CART_UPDATEITEM or CART_ADDITEM listener
 * throwing InvalidCartException, the stock gone since the cart was rendered) is told to the
 * shopper, where the live request used to answer 500. A refused action emits nothing: the
 * mini cart and the summary have nothing to follow.
 *
 * The cart is a generated Propel model: the test is skipped where those are not on the autoload.
 */
final class CartRefusalTest extends TestCase
{
    protected function setUp(): void
    {
        try {
            class_exists(CartModel::class);
        } catch (\Error) {
            self::markTestSkipped('The generated Propel models are not autoloaded.');
        }
    }

    public function testAPlusAModuleRefusesIsToldWithItsReasonAndEmitsNothing(): void
    {
        $responder = new LiveResponder();
        $component = $this->component(new InvalidCartException('Sold by 6'), $responder);

        $component->plus(0);

        self::assertSame('quantity', $component->refusal);
        self::assertSame('Sold by 6', $component->refusalReason);
        self::assertSame('Organic eggs', $component->refusalProduct);
        self::assertSame([], $responder->getEventsToEmit());
    }

    public function testALessAModuleRefusesIsToldTheSameWay(): void
    {
        $responder = new LiveResponder();
        $component = $this->component(new InvalidCartException('Sold by 6'), $responder);

        $component->minus(0);

        self::assertSame('quantity', $component->refusal);
        self::assertSame([], $responder->getEventsToEmit());
    }

    public function testATypedQuantityAModuleRefusesIsToldTheSameWay(): void
    {
        $responder = new LiveResponder();
        $component = $this->component(new InvalidCartException('Sold by 6'), $responder);
        // The data-model binding has already written the typed quantity on the line.
        $component->items[0]->quantity = 7;

        $component->onQuantityChanged(0, 6);

        self::assertSame('quantity', $component->refusal);
        self::assertSame([], $responder->getEventsToEmit());
    }

    public function testAModuleRefusalWithoutAReasonFallsBackToTheGenericSentence(): void
    {
        $component = $this->component(new InvalidCartException(''), new LiveResponder());

        $component->plus(0);

        self::assertSame('quantity', $component->refusal);
        self::assertSame('', $component->refusalReason);
    }

    public function testAStockGoneSinceTheRenderNeverShowsTheCoreMessage(): void
    {
        $responder = new LiveResponder();
        $component = $this->component(new NotEnoughStockException('Not enough stock for product REF-1'), $responder);

        $component->plus(0);

        self::assertSame('stock', $component->refusal);
        self::assertSame('', $component->refusalReason);
        self::assertSame([], $responder->getEventsToEmit());
    }

    public function testARestoreAModuleRefusesSaysTheLineWasNotPutBack(): void
    {
        $responder = new LiveResponder();
        $component = $this->component(new InvalidCartException('Sold by 6'), $responder);

        $component->restoreCartItem(7, 1, 5);

        self::assertSame('restore', $component->refusal);
        self::assertSame('Sold by 6', $component->refusalReason);
        self::assertSame([], $responder->getEventsToEmit());
    }

    public function testARestoreRefusedForTheStockSaysTheLineWasNotPutBack(): void
    {
        $component = $this->component(new NotEnoughStockException('Not enough stock for product REF-1'), new LiveResponder());

        $component->restoreCartItem(7, 1, 5);

        self::assertSame('restore', $component->refusal);
        self::assertSame('', $component->refusalReason);
    }

    public function testAnAcceptedQuantityStillEmitsTheCartEvent(): void
    {
        $responder = new LiveResponder();
        $component = $this->component(null, $responder);

        $component->plus(0);

        self::assertNull($component->refusal);
        self::assertSame([CheckoutEvents::UPDATE_ITEM_QUANTITY_EVENT], array_column($responder->getEventsToEmit(), 'event'));
    }

    public function testAnAcceptedRestoreStillEmitsTheCartEvent(): void
    {
        $responder = new LiveResponder();
        $component = $this->component(null, $responder);

        $component->restoreCartItem(7, 1, 5);

        self::assertNull($component->refusal);
        self::assertSame([CheckoutEvents::ADD_ITEM_EVENT], array_column($responder->getEventsToEmit(), 'event'));
    }

    private function component(?\Throwable $refusal, LiveResponder $responder): Cart
    {
        $cartItems = self::createStub(CartItemService::class);

        if (null === $refusal) {
            $cartItems->method('updateQuantityItem')->willReturn(self::createStub(CartItem::class));
            $cartItems->method('addItem')->willReturn(self::createStub(CartItem::class));
        } else {
            $cartItems->method('updateQuantityItem')->willThrowException($refusal);
            $cartItems->method('addItem')->willThrowException($refusal);
        }

        $cartRetriever = self::createStub(CartRetriever::class);
        $cartRetriever->method('fromSessionOrCreateNew')->willReturn(self::createStub(CartModel::class));

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

        $component = new Cart(
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
        $component->setLiveResponder($responder);
        // Stock not managed: the bounds of plus() and minus() would read the line's stock.
        $component->items = [CartItemDto::fromArray(['id' => 11, 'productId' => 1, 'productSaleElementsId' => 7, 'quantity' => 6, 'stock' => 100, 'stockManaged' => false, 'title' => 'Organic eggs'])];

        return $component;
    }
}
