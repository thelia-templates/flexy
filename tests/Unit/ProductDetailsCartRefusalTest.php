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

use FlexyBundle\Components\Layouts\ProductDetails\Base as ProductDetails;
use FlexyBundle\Event\CheckoutEvents;
use FlexyBundle\Service\RunningSaleResolver;
use PHPUnit\Framework\TestCase;
use Propel\Runtime\Collection\ObjectCollection;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\LiveResponder;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Api\Service\DataAccess\ProductSaleElementsAccessService;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Cart\Exception\InvalidCartException;
use Thelia\Domain\Cart\Exception\NotEnoughStockException;
use Thelia\Domain\Cart\Service\CartGiftWrappingService;
use Thelia\Domain\Cart\Service\CartItemService;
use Thelia\Domain\Cart\Service\CartRetriever;
use Thelia\Domain\Cart\Service\CartSelectionService;
use Thelia\Domain\Checkout\Service\GiftWrappingProvider;
use Thelia\Domain\Media\AltTextResolver;
use Thelia\Domain\Shipping\Service\PostageHandler;
use Thelia\Domain\Taxation\TaxEngine\TaxCalculatorFactoryInterface;
use Thelia\Model\Cart;
use Thelia\Model\CartItem;

/**
 * An add the cart refuses (a module's CART_ADDITEM listener throwing InvalidCartException, the
 * stock gone since the page was rendered) is told to the shopper: the component flags it and
 * emits nothing, where the live request used to answer 500. A successful add still emits the
 * events the mini cart listens to.
 *
 * The cart is a generated Propel model: the test is skipped where those are not on the autoload.
 */
final class ProductDetailsCartRefusalTest extends TestCase
{
    private const LINE_ID = 100;

    protected function setUp(): void
    {
        try {
            class_exists(Cart::class);
        } catch (\Error) {
            self::markTestSkipped('The generated Propel models are not autoloaded.');
        }
    }

    public function testAnAddTheCartRefusesIsFlaggedAndEmitsNothing(): void
    {
        $responder = new LiveResponder();
        $component = $this->component(new InvalidCartException('refused by a module rule'), $responder);

        $component->save();

        self::assertTrue($component->cartRefused);
        self::assertSame([], $responder->getEventsToEmit());
        self::assertSame([], $responder->getBrowserEventsToDispatch(), 'nothing is told to the page when the cart refused');
    }

    public function testAStockGoneSinceTheRenderIsFlaggedTheSameWay(): void
    {
        $responder = new LiveResponder();
        $component = $this->component(new NotEnoughStockException(), $responder);

        $component->save();

        self::assertTrue($component->cartRefused);
        self::assertSame([], $responder->getBrowserEventsToDispatch());
    }

    public function testAnAcceptedAddEmitsTheCartEvents(): void
    {
        $responder = new LiveResponder();
        $component = $this->component(null, $responder);

        $component->save();

        self::assertFalse($component->cartRefused);
        self::assertSame(['addToCart', CheckoutEvents::ADD_ITEM_EVENT], array_column($responder->getEventsToEmit(), 'event'));
    }

    public function testAnAcceptedAddIsToldToThePageWithTheDeclinationAndTheQuantity(): void
    {
        $responder = new LiveResponder();
        $component = $this->component(null, $responder, quantityAfter: 3);
        $component->formValues['quantity'] = 3;

        $component->save();

        self::assertSame(
            [['event' => CheckoutEvents::BROWSER_ADD_PSE, 'payload' => ['pse' => 7, 'quantity' => 3]]],
            $responder->getBrowserEventsToDispatch(),
        );
    }

    public function testAnAddedQuantityOnALineThatExistsIsToldAsTheQuantityGained(): void
    {
        $responder = new LiveResponder();
        $component = $this->component(null, $responder, quantityBefore: 2, quantityAfter: 5);
        $component->formValues['quantity'] = 3;

        $component->save();

        self::assertSame(
            [['event' => CheckoutEvents::BROWSER_ADD_PSE, 'payload' => ['pse' => 7, 'quantity' => 3]]],
            $responder->getBrowserEventsToDispatch(),
        );
    }

    public function testAQuantityThatReplacesTheOneOfTheLineIsToldAsTheDifference(): void
    {
        $responder = new LiveResponder();
        // `append` off: the form's 5 replaces the 2 the line held, which is a gain of 3, not of 5.
        $component = $this->component(null, $responder, quantityBefore: 2, quantityAfter: 5);
        $component->formValues['quantity'] = 5;
        $component->formValues['append'] = 0;

        $component->save();

        self::assertSame(
            [['event' => CheckoutEvents::BROWSER_ADD_PSE, 'payload' => ['pse' => 7, 'quantity' => 3]]],
            $responder->getBrowserEventsToDispatch(),
        );
    }

    public function testAQuantityThatReplacesTheOneOfTheLineWithALowerOneIsToldAsARemoval(): void
    {
        $responder = new LiveResponder();
        $component = $this->component(null, $responder, quantityBefore: 5, quantityAfter: 2);
        $component->formValues['quantity'] = 2;
        $component->formValues['append'] = 0;

        $component->save();

        self::assertSame(
            [['event' => CheckoutEvents::BROWSER_REMOVE_PSE, 'payload' => ['pse' => 7, 'quantity' => 3]]],
            $responder->getBrowserEventsToDispatch(),
        );
    }

    public function testAQuantityThatLeavesTheLineAsItWasIsToldToNobody(): void
    {
        $responder = new LiveResponder();
        $component = $this->component(null, $responder, quantityBefore: 3, quantityAfter: 3);
        $component->formValues['append'] = 0;

        $component->save();

        self::assertSame([], $responder->getBrowserEventsToDispatch());
    }

    private function line(int $id, int $quantity): CartItem
    {
        $line = self::createStub(CartItem::class);
        $line->method('getId')->willReturn($id);
        $line->method('hashCode')->willReturn('line-'.$id);
        $line->method('getQuantity')->willReturn((float) $quantity);

        return $line;
    }

    private function component(?\Throwable $refusal, LiveResponder $responder, int $quantityBefore = 0, int $quantityAfter = 0): ProductDetails
    {
        $cartItems = self::createStub(CartItemService::class);

        if (null === $refusal) {
            $cartItems->method('addItem')->willReturn($this->line(self::LINE_ID, $quantityAfter));
        } else {
            $cartItems->method('addItem')->willThrowException($refusal);
        }

        $cartRetriever = self::createStub(CartRetriever::class);
        $cartRetriever->method('fromSessionOrCreateNew')->willReturn(self::createStub(Cart::class));

        // The cart of the session as it is before the add: one line, unless the declination is not in it yet.
        $cartOfTheSession = self::createStub(Cart::class);
        $cartOfTheSession->method('getCartItems')->willReturn(new ObjectCollection(0 === $quantityBefore ? [] : [$this->line(self::LINE_ID, $quantityBefore)]));
        $cartRetriever->method('fromSession')->willReturn($cartOfTheSession);

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

        $component = new ProductDetails(
            self::createStub(DataAccessService::class),
            self::createStub(ProductSaleElementsAccessService::class),
            $formService,
            new CartFacade(
                $cartItems,
                self::createStub(CartSelectionService::class),
                self::createStub(PostageHandler::class),
                $cartRetriever,
                new CartGiftWrappingService(new GiftWrappingProvider(self::createStub(TaxCalculatorFactoryInterface::class))),
            ),
            new RequestStack(),
            new RunningSaleResolver(self::createStub(DataAccessService::class)),
            new AltTextResolver(),
        );
        $component->setLiveResponder($responder);
        // Virtual: the stock is not checked, which would read the shop configuration.
        $component->virtual = true;
        $component->productId = 1;
        $component->currentPse = ['id' => 7, 'quantity' => 3];
        $component->formValues = ['product' => 1, 'product_sale_elements_id' => 7, 'quantity' => 1, 'append' => 1, 'newness' => 0];

        return $component;
    }
}
