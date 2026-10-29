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
use FlexyBundle\Service\RunningSaleResolver;
use PHPUnit\Framework\TestCase;
use Propel\Runtime\Collection\ObjectCollection;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\LiveResponder;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Api\Service\DataAccess\ProductSaleElementsAccessService;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Cart\DTO\CartItemAddDTO;
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
 * The selector of the product sheet follows the rule of the selected sale element: it starts at
 * the smallest quantity sold, starts again when the shopper picks a sale element sold otherwise,
 * lists the lots up to the stock left, and hands the cart a quantity typed with a comma as the
 * decimal it is. The rule reaches the component in the sale element payload (keys of the front
 * API), so none of this needs a core that has the rules.
 *
 * The cart is a generated Propel model: the test is skipped where those are not on the autoload.
 */
final class ProductDetailsQuantityRuleTest extends TestCase
{
    private const LOT = ['minQuantity' => 2, 'quantityStep' => 6, 'quantityDecimals' => 0];
    private const WEIGHT = ['minQuantity' => 0.5, 'quantityStep' => 0.25, 'quantityDecimals' => 2, 'unitCode' => 'kg'];

    private ?CartItemAddDTO $added = null;

    protected function setUp(): void
    {
        try {
            class_exists(Cart::class);
        } catch (\Error) {
            self::markTestSkipped('The generated Propel models are not autoloaded.');
        }
    }

    public function testTheFormStartsAtTheSmallestQuantityOfTheSelectedSaleElement(): void
    {
        $component = $this->component(['id' => 8, 'quantity' => 100] + self::LOT);

        $form = (new \ReflectionMethod($component, 'instantiateForm'))->invoke($component);

        self::assertSame('6', $form->getData()['quantity'], 'a minimum of two sold by six starts at six');
    }

    public function testASaleElementSoldOtherwiseStartsTheSelectorAgainAndOneSoldAlikeKeepsTheQuantity(): void
    {
        $component = $this->component(['id' => 7, 'quantity' => 100], pses: [
            ['id' => 7, 'quantity' => 100],
            ['id' => 8, 'quantity' => 100] + self::LOT,
            ['id' => 9, 'quantity' => 100] + self::LOT,
        ]);
        $component->formValues['quantity'] = '3';

        $component->updateCurrentPseFromId('8');
        self::assertSame('6', $component->formValues['quantity']);

        $component->formValues['quantity'] = '12';
        $component->updateCurrentPseFromId('9');
        self::assertSame('12', $component->formValues['quantity']);
    }

    /**
     * Hypothesis (not settled by the ticket): the list stops at the last lot the stock left can
     * fill, and "maximum reached" is said once the stock left is below the smallest lot.
     */
    public function testALotListsItsMultiplesUpToTheStockLeft(): void
    {
        $component = $this->component(['id' => 8, 'quantity' => 20] + self::LOT, stockManaged: true);

        self::assertSame(['6', '12', '18'], array_column($component->getQuantityChoices(), 'value'));
        self::assertFalse($component->isMaxQuantityReached());
    }

    public function testTheMaximumIsReachedOnceTheStockLeftIsBelowTheSmallestLot(): void
    {
        $component = $this->component(['id' => 8, 'quantity' => 5] + self::LOT, stockManaged: true);

        self::assertTrue($component->isMaxQuantityReached());
        self::assertSame([], $component->getQuantityChoices());
    }

    public function testAStockLeftOfHalfAPieceIsTheMaximumOfASaleElementSoldByThePiece(): void
    {
        $component = $this->component(['id' => 7, 'quantity' => 7.5], stockManaged: true, inCart: 7.0);

        self::assertSame(0.5, $component->getRemainingStock());
        self::assertTrue($component->isMaxQuantityReached());
    }

    public function testAQuantityTypedAboveTheStockIsBroughtToTheLastLotUnderIt(): void
    {
        $component = $this->component(['id' => 8, 'quantity' => 20] + self::LOT, stockManaged: true);
        $component->formValues['quantity'] = '30';

        $component->save();

        self::assertSame(18.0, (float) $this->added?->toArray()['quantity']);
    }

    public function testAQuantityTypedWithACommaReachesTheCartAsADecimal(): void
    {
        if ('float' !== (string) (new \ReflectionParameter([CartItemAddDTO::class, '__construct'], 'quantity'))->getType()) {
            self::markTestSkipped('This core adds whole quantities only.');
        }

        $component = $this->component(['id' => 10, 'quantity' => 100] + self::WEIGHT);
        $component->formValues['quantity'] = '1,25';

        $component->save();

        self::assertSame(1.25, $this->added?->toArray()['quantity']);
    }

    public function testASaleElementSoldByThePieceStillAddsWholeQuantities(): void
    {
        $component = $this->component(['id' => 7, 'quantity' => 100]);
        $component->formValues['quantity'] = '3';

        $component->save();

        self::assertEquals(3, $this->added?->toArray()['quantity']);
    }

    /**
     * @param array<string, mixed>       $currentPse
     * @param list<array<string, mixed>> $pses
     */
    private function component(array $currentPse, array $pses = [], bool $stockManaged = false, float $inCart = 0.0): ProductDetails
    {
        $cartItems = self::createStub(CartItemService::class);
        $cartItems->method('addItem')->willReturnCallback(function (CartItemAddDTO $added): CartItem {
            $this->added = $added;
            $line = self::createStub(CartItem::class);
            $line->method('getId')->willReturn(100);
            $line->method('getQuantity')->willReturn((float) $added->toArray()['quantity']);

            return $line;
        });

        $line = self::createStub(CartItem::class);
        $line->method('getId')->willReturn(100);
        $line->method('hashCode')->willReturn('line-100');
        $line->method('getProductSaleElementsId')->willReturn((int) $currentPse['id']);
        $line->method('getQuantity')->willReturn($inCart);

        $cartOfTheSession = self::createStub(Cart::class);
        $cartOfTheSession->method('getCartItems')->willReturn(new ObjectCollection(0.0 === $inCart ? [] : [$line]));
        $cartRetriever = self::createStub(CartRetriever::class);
        $cartRetriever->method('fromSessionOrCreateNew')->willReturn(self::createStub(Cart::class));
        $cartRetriever->method('fromSession')->willReturn($cartOfTheSession);

        $saleElements = self::createStub(ProductSaleElementsAccessService::class);
        $saleElements->method('psesByProduct')->willReturn(json_encode($pses ?: [$currentPse], \JSON_THROW_ON_ERROR));

        // The core's CART_ADD reads its quantity with a NumberType, which takes "1,25" as "1.25".
        $formService = self::createStub(FormServiceInterface::class);
        $formService->method('getFormByName')->willReturnCallback(static function (string $name, array $data = []): Form {
            $builder = Forms::createFormFactory()->createNamedBuilder('thelia_cart_add', FormType::class, $data);

            foreach (['product', 'product_sale_elements_id', 'append', 'newness'] as $field) {
                $builder->add($field, TextType::class);
            }

            $builder->add('quantity', NumberType::class);
            $form = $builder->getForm();
            self::assertInstanceOf(Form::class, $form);

            return $form;
        });

        $arguments = [
            self::createStub(DataAccessService::class),
            $saleElements,
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
        ];

        $component = new ProductDetails(...$arguments);

        // The core publishes what may still be sold only when it enforces a limit: the stock here.
        if ($stockManaged) {
            $currentPse['availableQuantity'] ??= $currentPse['quantity'] ?? 0;
        }

        $component->setLiveResponder(new LiveResponder());
        $component->virtual = !$stockManaged;
        $component->productId = 1;
        $component->currentPse = $currentPse;
        $component->formValues = ['product' => 1, 'product_sale_elements_id' => $currentPse['id'], 'quantity' => '1', 'append' => 1, 'newness' => 0];

        return $component;
    }
}
