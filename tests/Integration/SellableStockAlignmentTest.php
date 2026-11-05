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

use FlexyBundle\Components\Layouts\ProductDetails\Base as ProductDetails;
use FlexyBundle\DTO\CartItemDto;
use FlexyBundle\Service\BundleProductReader;
use FlexyBundle\Service\BundleSelectionService;
use FlexyBundle\Service\CartStockService;
use FlexyBundle\Service\ProductImageResolver;
use FlexyBundle\Service\RunningSaleResolver;
use Propel\Runtime\Propel;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\LiveResponder;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Api\Service\DataAccess\ProductSaleElementsAccessService;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Catalog\Stock\StockSettings;
use Thelia\Domain\Media\AltTextResolver;
use Thelia\Model\CartItem;
use Thelia\Model\CartItemQuery;
use Thelia\Model\CartQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The product sheet and the cart give the same number: what the shop may still sell of a sale element
 * (the stock and what its sale mode allows below zero), the core's answer, never the stock alone.
 */
final class SellableStockAlignmentTest extends IntegrationTestCase
{
    private ?FixtureFactory $factory = null;

    protected function setUp(): void
    {
        parent::setUp();

        ConfigQuery::write('check-available-stock', '1');
    }

    public function testALimitedPreorderAtZeroStockIsSellableUpToItsLimit(): void
    {
        $product = $this->product(0, policy: 2, limit: 10.0, restockDate: '2026-12-15');
        $sheet = $this->sheet($product);

        self::assertTrue($sheet->isStockManaged());
        self::assertSame(10, $sheet->getRemainingStock());
        self::assertFalse($sheet->isMaxQuantityReached());
        self::assertTrue($sheet->currentPse['isPreorder']);
        self::assertSame('2026-12-15', $sheet->currentPse['restockDate']);
    }

    public function testWhatTheCartAlreadyHoldsIsDeductedFromTheSheet(): void
    {
        $product = $this->product(0, policy: 2, limit: 10.0);
        $this->lineOf($product, 4);

        self::assertSame(6, $this->sheet($product)->getRemainingStock());
    }

    public function testTheSheetAndTheCartLineGiveTheSameNumber(): void
    {
        $product = $this->product(3, policy: 2, limit: 10.0);
        $line = $this->lineOf($product, 5);
        $pse = $product->getProductSaleElementss()->getFirst();

        $cartStock = $this->getService(CartStockService::class)->stockOf($line, $pse);

        self::assertSame(13, $cartStock['stock'], 'the stock and the limit, not the stock alone');
        self::assertTrue($cartStock['managed']);
        self::assertSame($cartStock['stock'] - 5, $this->sheet($product)->getRemainingStock());
    }

    public function testASaleModeThatRefusesAtZeroKeepsTheSoldOutSheet(): void
    {
        $product = $this->product(0, policy: 0);
        $sheet = $this->sheet($product);

        self::assertTrue($sheet->isStockManaged());
        self::assertSame(0, $sheet->getRemainingStock());
        self::assertFalse($sheet->currentPse['isPreorder']);
        self::assertSame(0.0, (float) $sheet->currentPse['availableQuantity']);

        $line = $this->lineOf($product, 1);
        self::assertSame(
            ['stock' => 0, 'managed' => true, 'preorder' => false],
            $this->getService(CartStockService::class)->stockOf($line, $product->getProductSaleElementss()->getFirst()),
        );
    }

    public function testASaleModeWithoutLimitHasNoBoundOnTheSheetNorOnTheCart(): void
    {
        $product = $this->product(0, policy: 1);
        $sheet = $this->sheet($product);

        self::assertFalse($sheet->isStockManaged(), 'no limit to enforce');
        self::assertSame(\PHP_INT_MAX, $sheet->getRemainingStock());
        self::assertTrue($sheet->currentPse['isPreorder']);

        $line = $this->lineOf($product, 3);
        self::assertFalse($this->getService(CartStockService::class)->stockOf($line, $product->getProductSaleElementss()->getFirst())['managed']);
    }

    public function testASheetWhoseSaleElementCarriesNoAvailableQuantityIsWithoutLimit(): void
    {
        // The key is absent from the payload when the core has no limit to publish.
        $sheet = $this->sheet($this->product(0, policy: 0));
        unset($sheet->currentPse['availableQuantity']);

        self::assertFalse($sheet->isStockManaged());
        self::assertSame(\PHP_INT_MAX, $sheet->getRemainingStock());
        self::assertFalse($sheet->isMaxQuantityReached());
    }

    public function testAVirtualProductOrAShopThatDoesNotCheckTheStockHasNoLimitOnTheSheet(): void
    {
        $virtual = $this->product(0, policy: 0);
        $virtual->setVirtual(1)->save();
        self::assertFalse($this->sheet($virtual)->isStockManaged(), 'virtual: the core publishes no limit');

        ConfigQuery::write('check-available-stock', '0');
        self::assertFalse($this->sheet($this->product(0, policy: 0))->isStockManaged(), 'stock not checked: the core publishes no limit');
    }

    public function testALineSoldBelowZeroIsAPreorderAsTheCoreSaysIt(): void
    {
        $product = $this->product(2, policy: 2, limit: 10.0, restockDate: '2026-12-15');
        $pse = $product->getProductSaleElementss()->getFirst();
        $service = $this->getService(CartStockService::class);

        self::assertFalse($service->stockOf($this->lineOf($product, 2), $pse)['preorder'], 'covered by the stock');
        self::assertTrue($service->stockOf($this->lineOf($product, 5), $pse)['preorder']);
    }

    public function testAPreorderLineOfTheCartWearsTheSameStockTagAsTheSheet(): void
    {
        $product = $this->product(0, policy: 2, limit: 10.0, restockDate: '2026-12-15');
        $line = $this->lineOf($product, 3);
        $dto = CartItemDto::fromArray([
            'id' => $line->getId(),
            'productId' => $product->getId(),
            'productSaleElementsId' => $line->getProductSaleElementsId(),
            'quantity' => 3,
            'stock' => 10,
            'stockManaged' => true,
            'title' => 'Plain',
            'isPreorder' => true,
            'restockDate' => '2026-12-15',
        ]);
        static::getContainer()->get('twig')->addGlobal('lang_code', 'en');
        $renderer = static::getContainer()->get('ux.twig_component.component_renderer');
        \assert($renderer instanceof ComponentRendererInterface);

        $html = $renderer->createAndRender('Organisms:CartItem:Base', ['cartItem' => $dto, 'index' => 0]);

        self::assertStringContainsString('StockTag--preorder', $html);
        self::assertStringContainsString('Restock expected on', $html);
    }

    public function testNoPreorderWhenTheShopDoesNotCheckTheStock(): void
    {
        ConfigQuery::write('check-available-stock', '0');
        $product = $this->product(0, policy: 2, limit: 10.0);
        $pse = $product->getProductSaleElementss()->getFirst();

        $answer = $this->getService(CartStockService::class)->stockOf($this->lineOf($product, 1), $pse);

        self::assertFalse($answer['preorder']);
        self::assertFalse($answer['managed']);
    }

    public function testTheStockOfACartIsReadInANumberOfQueriesThatDoesNotGrowWithItsLines(): void
    {
        ConfigQuery::write(StockSettings::RESERVATION_ENABLED, '1');
        $service = $this->getService(CartStockService::class);

        $read = function (int $lines) use ($service): int {
            $cart = $this->freshCart();
            $stocked = [];

            for ($i = 0; $i < $lines; ++$i) {
                $product = $this->product(3, policy: 2, limit: 10.0);
                $line = $this->factory()->cartItem($cart, $product, null, ['quantity' => 2.0]);
                $stocked[(int) $line->getId()] = [$line, $product->getProductSaleElementss()->getFirst()];
            }

            $cart = CartQuery::create()->findPk($cart->getId());
            $stocked = array_map(static fn (array $row): array => [CartItemQuery::create()->findPk($row[0]->getId()), $row[1]], $stocked);
            $service->stocksOf($stocked, $cart);

            $connection = Propel::getConnection('TheliaMain');
            $connection->useDebug(true);
            $before = $connection->getQueryCount();
            $answers = $service->stocksOf($stocked, $cart);
            $queries = $connection->getQueryCount() - $before;

            self::assertCount($lines, $answers);
            foreach ($answers as $answer) {
                self::assertSame(13, $answer['stock']);
            }

            return $queries;
        };

        $one = $read(1);
        $five = $read(5);

        self::assertSame($one, $five, 'one pass for the whole cart, whatever the number of lines');
    }

    public function testTheCartCheckOfTheStockIsTheSellableOne(): void
    {
        $product = $this->product(0, policy: 2, limit: 10.0);
        $line = $this->lineOf($product, 10);
        $service = $this->getService(CartStockService::class);

        self::assertFalse($service->hasInsufficientStock($line->getCart()), 'ten lines of ten sellable');

        $line->setQuantity(11.0)->save();

        self::assertTrue($service->hasInsufficientStock(CartQuery::create()->findPk($line->getCartId())));
    }

    private function product(int $stock, int $policy, ?float $limit = null, ?string $restockDate = null): Product
    {
        $factory = $this->factory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['basePrice' => 50.0, 'baseQuantity' => $stock]);
        $pse = $product->getProductSaleElementss()->getFirst();
        ProductSaleElementsQuery::create()->filterById($pse->getId())->update([
            'OutOfStockPolicy' => $policy,
            'PreorderLimit' => $limit,
            'RestockDate' => $restockDate,
        ]);
        $pse->reload();

        return $product;
    }

    private function lineOf(Product $product, int $quantity): CartItem
    {
        $cart = $this->sessionCart();
        $line = CartItemQuery::create()->filterByCartId($cart->getId())->filterByProductId($product->getId())->findOne();

        if (null !== $line) {
            $line->setQuantity((float) $quantity)->save();

            return $line;
        }

        return $this->factory()->cartItem($cart, $product, null, ['quantity' => (float) $quantity]);
    }

    private ?\Thelia\Model\Cart $cart = null;

    private function sessionCart(): \Thelia\Model\Cart
    {
        if (null !== $this->cart) {
            return $this->cart;
        }

        $factory = $this->factory();
        $cart = $factory->cart(null, ['currency' => $factory->currency()]);
        $stack = static::getContainer()->get('request_stack');
        \assert($stack instanceof RequestStack);
        $session = $stack->getCurrentRequest()?->getSession();
        \assert($session instanceof Session);
        $session->setSessionCart(CartQuery::create()->findPk($cart->getId()));

        return $this->cart = CartQuery::create()->findPk($cart->getId());
    }

    private function freshCart(): \Thelia\Model\Cart
    {
        $factory = $this->factory();

        return $factory->cart(null, ['currency' => $factory->currency()]);
    }

    private function sheet(Product $product): ProductDetails
    {
        $this->sessionCart();

        // The form of the shop carries a CSRF token a test cannot post: a plain one holds the five fields the sheet reads.
        $formService = self::createStub(FormServiceInterface::class);
        $formService->method('getFormByName')->willReturnCallback(static function (): Form {
            $builder = Forms::createFormFactory()->createNamedBuilder('thelia_cart_add', FormType::class);

            foreach (['product', 'product_sale_elements_id', 'quantity', 'append', 'newness'] as $field) {
                $builder->add($field, TextType::class);
            }

            $form = $builder->getForm();
            \assert($form instanceof Form);

            return $form;
        });

        $component = new ProductDetails(
            $this->getService(DataAccessService::class),
            $this->getService(ProductSaleElementsAccessService::class),
            $formService,
            $this->getService(CartFacade::class),
            $this->getService(RequestStack::class),
            $this->getService(RunningSaleResolver::class),
            $this->getService(AltTextResolver::class),
            bundleReader: $this->getService(BundleProductReader::class),
            bundleSelectionService: $this->getService(BundleSelectionService::class),
            productImageResolver: $this->getService(ProductImageResolver::class),
        );
        $data = ['id' => $product->getId(), 'isBundle' => false, 'i18ns' => ['title' => 'Plain']];
        $component->mount($data, 'Plain');
        $component->setLiveResponder(new LiveResponder());

        return $component;
    }

    private function factory(): FixtureFactory
    {
        return $this->factory ??= new FixtureFactory(Propel::getConnection('TheliaMain'));
    }
}
