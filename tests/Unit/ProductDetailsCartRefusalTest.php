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
    }

    public function testAStockGoneSinceTheRenderIsFlaggedTheSameWay(): void
    {
        $component = $this->component(new NotEnoughStockException(), new LiveResponder());

        $component->save();

        self::assertTrue($component->cartRefused);
    }

    public function testAnAcceptedAddEmitsTheCartEvents(): void
    {
        $responder = new LiveResponder();
        $component = $this->component(null, $responder);

        $component->save();

        self::assertFalse($component->cartRefused);
        self::assertSame(['addToCart', CheckoutEvents::ADD_ITEM_EVENT], array_column($responder->getEventsToEmit(), 'event'));
    }

    private function component(?\Throwable $refusal, LiveResponder $responder): ProductDetails
    {
        $cartItems = self::createStub(CartItemService::class);

        if (null === $refusal) {
            $cartItems->method('addItem')->willReturn(self::createStub(CartItem::class));
        } else {
            $cartItems->method('addItem')->willThrowException($refusal);
        }

        $cartRetriever = self::createStub(CartRetriever::class);
        $cartRetriever->method('fromSessionOrCreateNew')->willReturn(self::createStub(Cart::class));

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
