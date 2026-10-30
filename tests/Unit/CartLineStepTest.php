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
use FlexyBundle\DTO\QuantityRuleDTO;
use FlexyBundle\Event\CheckoutEvents;
use FlexyBundle\Service\CartQuantity;
use FlexyBundle\Service\CartStockService;
use PHPUnit\Framework\TestCase;
use Symfony\UX\LiveComponent\LiveResponder;
use Thelia\Api\Service\DataAccess\AttributeAccessService;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Cart\DTO\CartItemUpdateQuantityDTO;
use Thelia\Domain\Cart\Service\CartGiftWrappingService;
use Thelia\Domain\Cart\Service\CartItemService;
use Thelia\Domain\Cart\Service\CartRetriever;
use Thelia\Domain\Cart\Service\CartSelectionService;
use Thelia\Domain\Checkout\Service\GiftWrappingProvider;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Shipping\Service\PostageHandler;
use Thelia\Domain\Taxation\TaxEngine\TaxCalculatorFactoryInterface;
use Thelia\Model\Cart as CartModel;
use Thelia\Model\CartItem;

/**
 * The "+" and "-" of a cart line move it by the step of its rule (a lot of six by six, a
 * kilogram sold by the quarter by a quarter), no further than the stock allows, and a quantity
 * typed with decimals reaches the cart as it was typed. What the cart is handed is read off the
 * DTO the item service receives; the line is the one at index 0, sale element 7.
 *
 * A decimal quantity only reaches a core whose cart takes one: on an older core the decimal
 * cases are skipped, the whole ones still run.
 */
final class CartLineStepTest extends TestCase
{
    /** @var list<float|int> */
    private array $sent = [];

    protected function setUp(): void
    {
        try {
            class_exists(CartModel::class);
        } catch (\Error) {
            self::markTestSkipped('The generated Propel models are not autoloaded.');
        }

        $this->sent = [];
    }

    public function testAPlusOnALotOfSixAddsSix(): void
    {
        $this->cart(6, new QuantityRuleDTO(minQuantity: 2, step: 6), stock: 100)->plus(0);

        self::assertSame([12.0], $this->sent);
    }

    public function testAMinusOnALotOfSixTakesSix(): void
    {
        $this->cart(18, new QuantityRuleDTO(minQuantity: 2, step: 6), stock: 100)->minus(0);

        self::assertSame([12.0], $this->sent);
    }

    public function testAPlusStopsAtTheLastLotTheStockHolds(): void
    {
        // Fifteen left: twelve is the last lot of six, eighteen would be refused for the stock.
        $this->cart(12, new QuantityRuleDTO(minQuantity: 2, step: 6), stock: 15)->plus(0);

        self::assertSame([12.0], $this->sent);
    }

    public function testAPlusOnAWeightAddsAQuarter(): void
    {
        $this->skipUnlessTheCartTakesDecimals();

        $responder = new LiveResponder();
        $this->cart(1.25, $this->quarterKilogram(), stock: 7.5, responder: $responder)->plus(0);

        self::assertSame([1.5], $this->sent);
        self::assertSame(
            [['event' => CheckoutEvents::BROWSER_ADD_PSE, 'payload' => ['pse' => 7, 'quantity' => 0.25]]],
            $responder->getBrowserEventsToDispatch(),
        );
    }

    public function testAMinusOnAWeightTakesAQuarter(): void
    {
        $this->skipUnlessTheCartTakesDecimals();

        $this->cart(1.5, $this->quarterKilogram(), stock: 7.5)->minus(0);

        self::assertSame([1.25], $this->sent);
    }

    public function testAMinusOnALineAboveTheStockBringsItToTheLastValidQuantityUnderIt(): void
    {
        $this->skipUnlessTheCartTakesDecimals();

        // 2.5 kg in the cart, 1.3 left: 1.25 is the last quarter the stock holds.
        $this->cart(2.5, $this->quarterKilogram(), stock: 1.3)->minus(0);

        self::assertSame([1.25], $this->sent);
    }

    public function testADecimalQuantityTypedReachesTheCartAsTyped(): void
    {
        $this->skipUnlessTheCartTakesDecimals();

        $responder = new LiveResponder();
        $cart = $this->cart(1.25, $this->quarterKilogram(), stock: 7.5, responder: $responder);
        // The data-model binding has written the typed quantity on the line; the line had 1.25.
        $cart->items[0]->quantity = 1.5;

        $cart->onQuantityChanged(0, 1.25);

        self::assertSame([1.5], $this->sent);
        self::assertSame([['event' => CheckoutEvents::BROWSER_ADD_PSE, 'payload' => ['pse' => 7, 'quantity' => 0.25]]], $responder->getBrowserEventsToDispatch());
    }

    public function testALineWithoutARuleStillMovesByOne(): void
    {
        $this->cart(2, new QuantityRuleDTO(), stock: 5)->plus(0);
        $this->cart(2, new QuantityRuleDTO(), stock: 5)->minus(0);

        self::assertSame([3.0, 1.0], $this->sent);
    }

    public function testTheCartCountsItsLinesOnceALineIsSoldByMeasure(): void
    {
        $cart = $this->cart(2, new QuantityRuleDTO(), stock: 5);
        self::assertSame(2, $cart->getTotalItems(), 'two pieces');

        $cart->items[] = CartItemDto::fromArray(['id' => 101, 'quantity' => 1.25, 'quantityRule' => $this->quarterKilogram()->toArray()]);

        self::assertSame(2, $cart->getTotalItems(), 'two pieces and 1.25 kg are two lines');
    }

    private function quarterKilogram(): QuantityRuleDTO
    {
        return new QuantityRuleDTO(minQuantity: 0.5, step: 0.25, decimals: 2, unitCode: 'kg');
    }

    private function skipUnlessTheCartTakesDecimals(): void
    {
        if (!CartQuantity::cartTakesDecimals()) {
            self::markTestSkipped('The cart of this core counts in whole pieces.');
        }
    }

    private function cart(float $quantity, QuantityRuleDTO $rule, float $stock, ?LiveResponder $responder = null): Cart
    {
        $cartRetriever = self::createStub(CartRetriever::class);
        $cartRetriever->method('fromSessionOrCreateNew')->willReturn(self::createStub(CartModel::class));
        $cartItems = self::createStub(CartItemService::class);
        $cartItems->method('updateQuantityItem')->willReturnCallback(function (CartItemUpdateQuantityDTO $dto): CartItem {
            $this->sent[] = (float) $dto->toArray()['quantity'];

            return self::createStub(CartItem::class);
        });

        $cart = new Cart(
            new CartFacade(
                $cartItems,
                self::createStub(CartSelectionService::class),
                self::createStub(PostageHandler::class),
                $cartRetriever,
                new CartGiftWrappingService(new GiftWrappingProvider(self::createStub(TaxCalculatorFactoryInterface::class))),
            ),
            self::createStub(FormServiceInterface::class),
            new CartStockService(),
            self::createStub(AttributeAccessService::class),
            self::createStub(LangService::class),
        );
        $cart->setLiveResponder($responder ?? new LiveResponder());
        $cart->items = [CartItemDto::fromArray([
            'id' => 100,
            'cartId' => 1,
            'productId' => 70,
            'productSaleElementsId' => 7,
            'quantity' => $quantity,
            'stock' => $stock,
            'stockManaged' => true,
            'isOffered' => false,
            'title' => 'Lexie',
            'quantityRule' => $rule->toArray(),
        ])];

        return $cart;
    }
}
