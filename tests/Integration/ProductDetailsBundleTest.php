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
use FlexyBundle\Service\BundleProductReader;
use FlexyBundle\Service\BundleSelectionService;
use FlexyBundle\Service\ProductImageResolver;
use FlexyBundle\Service\RunningSaleResolver;
use FlexyBundle\Tests\Support\BuildsBundles;
use Propel\Runtime\Propel;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\LiveResponder;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Api\Service\DataAccess\ProductSaleElementsAccessService;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Catalog\Bundle\BundleCompositionService;
use Thelia\Domain\Catalog\Bundle\BundlePriceMode;
use Thelia\Domain\Media\AltTextResolver;
use Thelia\Model\CartItemQuery;
use Thelia\Model\CartQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The sheet of a bundle: its components listed the way the merchant allowed them to be changed, the price of
 * the composition as it stands asked of the core once per change, the add to the cart carrying that composition
 * and the refusal of the core told through the channel the sheet already has. Runs on an installed shop
 * (bin/test-prepare).
 */
final class ProductDetailsBundleTest extends IntegrationTestCase
{
    use BuildsBundles;

    public function testTheComponentsAreListedWithWhatTheShopperMayDoWithEach(): void
    {
        $example = $this->example();
        $view = $this->component($example)->getBundle();

        self::assertSame(['Mountain bike', 'Helmet', 'Chain lock'], array_column($view['components'], 'title'));
        // The bike and the helmets are fixed by the merchant, the lock is a quantity from 0 to 3, left out by default.
        self::assertSame(['none', 'none', 'quantity'], array_column($view['components'], 'control'));
        self::assertSame([true, true, false], array_column($view['components'], 'required'));
        self::assertSame(['1', '2', '0'], array_column($view['components'], 'quantity'));
        self::assertSame(['0', '3'], [$view['components'][2]['minQuantity'], $view['components'][2]['maxQuantity']]);
        self::assertFalse($view['components'][2]['included']);
    }

    public function testTheDefaultCompositionIsPricedByTheCore(): void
    {
        $view = $this->component($this->example())->getBundle();

        self::assertEqualsWithDelta(604.80, $view['pricing']['price'], 0.001);
        self::assertEqualsWithDelta(672.0, $view['pricing']['originalPrice'], 0.001);
        self::assertEqualsWithDelta(67.20, $view['pricing']['saving'], 0.001);
        self::assertFalse($view['unavailable']);
        self::assertFalse($view['refused']);
    }

    public function testAChangeOfTheSelectionChangesThePriceAndTheListedQuantity(): void
    {
        $example = $this->example();
        $component = $this->component($example);
        $component->bundleSelection = [(int) $example['lock']->getId() => '2'];

        $view = $component->getBundle();

        // 600 struck, 540 in the plan: 720 and 648 with the tax of the product of the bundle.
        self::assertEqualsWithDelta(648.0, $view['pricing']['price'], 0.001);
        self::assertEqualsWithDelta(720.0, $view['pricing']['originalPrice'], 0.001);
        self::assertSame('2', $view['components'][2]['quantity']);
        self::assertTrue($view['components'][2]['included']);
    }

    public function testASelectionTheCoreRefusesIsShownAsRefusedWithTheDefaultCompositionInItsPlace(): void
    {
        $example = $this->example();
        $component = $this->component($example);
        $component->bundleSelection = [(int) $example['lock']->getId() => '4'];

        $view = $component->getBundle();

        self::assertTrue($view['refused']);
        self::assertSame('0', $view['components'][2]['quantity']);
    }

    public function testEveryQuantityFieldHasItsKeyInTheStateFromTheMount(): void
    {
        $example = $this->example();
        $component = $this->component($example);

        // The browser writes only a model that exists in the state it was rendered with: the key is there before anything was touched,
        // at the default quantity, for the one component whose quantity the shopper picks (the lock), and for no other.
        self::assertSame([(int) $example['lock']->getId() => '0'], $component->bundleSelection);
        self::assertSame(array_column($component->getBundle()['components'], 'quantity'), ['1', '2', '0']);
        self::assertEqualsWithDelta(604.80, $component->getBundle()['pricing']['price'], 0.001, 'a field at its default quantity is not a change');
    }

    public function testTheCompositionOfASelectionIsAskedOfTheCoreOncePerRender(): void
    {
        $example = $this->example();
        $selection = [(int) $example['lock']->getId() => '2'];
        $queries = static function (callable $work): int {
            $connection = Propel::getConnection('TheliaMain');
            $connection->useDebug(true);
            $before = $connection->getQueryCount();
            $work();

            return $connection->getQueryCount() - $before;
        };

        // Once for nothing: what the first read of a shop loads (the configuration, the language) is not the question.
        $warmUp = $this->component($example);
        $warmUp->bundleSelection = $selection;
        $warmUp->getBundle();

        $alone = $this->component($example);
        $alone->bundleSelection = $selection;
        $viewOnly = $queries(static fn () => $alone->getBundle());

        $rendered = $this->component($example);
        $rendered->bundleSelection = $selection;
        $withTheRefusalCheck = $queries(static function () use ($rendered): void {
            $rendered->forgetARefusedSelection();
            $rendered->getBundle();
        });

        self::assertSame($viewOnly, $withTheRefusalCheck, 'The refusal check and the view share one composition.');
    }

    public function testABundleOfMoreThanOnePageOfComponentsIsReadWhole(): void
    {
        $example = $this->example();
        $factory = $this->factory();
        $service = $this->getService(BundleCompositionService::class);
        $category = $factory->category();
        $taxRule = $factory->taxRule();
        $currency = $factory->currency();
        for ($i = 0; $i < 117; ++$i) {
            $service->addItem((int) $example['bundle']->getId(), $this->saleElementsIdOf($factory->product($category, $taxRule, $currency, ['title' => 'Extra '.$i, 'basePrice' => 1.0, 'baseQuantity' => 5])), '1', '0', '1', false);
        }

        $rows = $this->getService(BundleProductReader::class)->read((int) $example['bundle']->getId());

        // The API answers 100 at most: the 20 components after the first page are on the sheet too.
        self::assertCount(120, $rows);
        self::assertCount(120, array_unique(array_column($rows, 'itemId')));
        self::assertSame('Extra 116', $rows[119]['title']);
    }

    public function testAnOptionalComponentThatIsEitherInOrOutIsASwitchTheShopperToggles(): void
    {
        $example = $this->example();
        $bell = $this->getService(BundleCompositionService::class)->addItem(
            (int) $example['bundle']->getId(),
            $this->saleElementsIdOf($this->factory()->product($this->factory()->category(), $this->factory()->taxRule(), $this->factory()->currency(), ['title' => 'Bell', 'basePrice' => 10.0, 'baseQuantity' => 5])),
            '1',
            null,
            null,
            false,
        );
        $component = $this->component($example);
        $switch = static fn (array $view): array => array_values(array_filter($view['components'], static fn (array $row): bool => 'Bell' === $row['title']))[0];

        self::assertSame('switch', $switch($component->getBundle())['control']);
        self::assertFalse($switch($component->getBundle())['included']);

        $component->toggleBundleItem((int) $bell->getId());
        self::assertTrue($switch($component->getBundle())['included'], 'ticked');

        $component->toggleBundleItem((int) $bell->getId());
        self::assertFalse($switch($component->getBundle())['included'], 'unticked');

        // A component the merchant requires is not the shopper's to take out.
        $component->toggleBundleItem((int) $example['bike']->getId());
        self::assertSame([], array_diff_key($component->bundleSelection, [(int) $bell->getId() => true, (int) $example['lock']->getId() => true]), 'only the bell the shopper toggled and the quantity field of the lock');
    }

    public function testABundleInFixedModeIsPricedLikeAProductAndKeepsItsPromoTag(): void
    {
        $component = $this->component($this->example(BundlePriceMode::Fixed));

        self::assertNull($component->getBundle()['pricing']);
        self::assertFalse($component->isComputedBundle());
    }

    public function testACartedBundleCarriesTheCompositionOfTheSheet(): void
    {
        $example = $this->example();
        $component = $this->component($example);
        $component->bundleSelection = [(int) $example['lock']->getId() => '2'];

        $component->save();

        self::assertFalse($component->cartRefused);
        $line = CartItemQuery::create()->filterByProductId($example['bundle']->getId())->findOne();
        self::assertNotNull($line);
        self::assertSame(
            [['bundleItemId' => (int) $example['bike']->getId(), 'quantity' => '1'], ['bundleItemId' => (int) $example['helmet']->getId(), 'quantity' => '2'], ['bundleItemId' => (int) $example['lock']->getId(), 'quantity' => '2']],
            $line->getBundleCompositionValue()->toArray(),
        );
        self::assertEqualsWithDelta(600.0, (float) $line->getPrice(), 0.001, 'the price the cart wrote is the one of the composition (untaxed)');
        self::assertEqualsWithDelta(540.0, (float) $line->getPromoPrice(), 0.001);
    }

    public function testAnAddThatTheComponentsCannotSupplyIsRefusedByTheChannelOfTheSheet(): void
    {
        ConfigQuery::write('check-available-stock', '1');
        $example = $this->example();
        $component = $this->component($example);
        // The helmets run out between the render and the click.
        Propel::getConnection('TheliaMain')->exec(\sprintf('UPDATE product_sale_elements SET quantity = 1 WHERE id = %d', $this->saleElementsIdOf($example['helmetProduct'])));

        $component->save();

        self::assertTrue($component->cartRefused);
        self::assertSame(0, CartItemQuery::create()->filterByProductId($example['bundle']->getId())->count());
    }

    public function testASelectionPutAsideIsNotAddedInPlaceOfTheOneTheShopperMade(): void
    {
        $example = $this->example();
        $component = $this->component($example);
        $component->bundleSelection = [(int) $example['lock']->getId() => '4'];

        $component->save();

        self::assertTrue($component->cartRefused);
        self::assertSame(0, CartItemQuery::create()->filterByProductId($example['bundle']->getId())->count());
    }

    public function testTheStockOfABundleIsWhatItsComponentsAllow(): void
    {
        ConfigQuery::write('check-available-stock', '1');
        $component = $this->component($this->example());

        // min(7 / 1, 5 / 2) = 2, read by the core from the sale element of the bundle, which itself holds 0.
        self::assertTrue($component->isStockManaged());
        self::assertSame(2, $component->getRemainingStock());
    }

    public function testABundleOfVirtualComponentsOnlyHasNoLimitToShow(): void
    {
        ConfigQuery::write('check-available-stock', '1');
        $example = $this->example();
        foreach (['bikeProduct', 'helmetProduct'] as $key) {
            Propel::getConnection('TheliaMain')->exec(\sprintf('UPDATE product SET `virtual` = 1 WHERE id = %d', $example[$key]->getId()));
        }
        $component = $this->component($example);
        $component->currentPse['quantity'] = 2147483647;

        self::assertFalse($component->isStockManaged());
        self::assertSame(\PHP_INT_MAX, $component->getRemainingStock());
    }

    public function testAProductThatIsNotABundleKeepsItsSheetUntouched(): void
    {
        $factory = $this->factory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['title' => 'Plain', 'baseQuantity' => 3]);
        $component = $this->mounted(['id' => $product->getId(), 'isBundle' => false, 'i18ns' => ['title' => 'Plain']]);

        self::assertNull($component->getBundle());
        self::assertFalse($component->isComputedBundle());
        self::assertSame([], $component->bundleRows);
    }

    /**
     * @param array<string, mixed> $example
     */
    private function component(array $example): ProductDetails
    {
        return $this->mounted(['id' => $example['bundle']->getId(), 'isBundle' => true, 'i18ns' => ['title' => 'Bike pack']]);
    }

    /**
     * @param array<string, mixed> $product
     */
    private function mounted(array $product): ProductDetails
    {
        $this->putACartInSession();

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
        $component->mount($product, (string) ($product['i18ns']['title'] ?? ''));
        $component->setLiveResponder(new LiveResponder());
        $component->formValues = ['product' => $product['id'], 'product_sale_elements_id' => $component->currentPse['id'] ?? null, 'quantity' => 1, 'append' => 1, 'newness' => 0];

        return $component;
    }

    private function putACartInSession(): void
    {
        $factory = $this->factory();
        $cart = $factory->cart(null, ['currency' => $factory->currency()]);
        $stack = static::getContainer()->get('request_stack');
        \assert($stack instanceof RequestStack);
        $session = $stack->getCurrentRequest()?->getSession();
        \assert($session instanceof Session);
        $session->setSessionCart(CartQuery::create()->findPk($cart->getId()));
    }

    /**
     * @return array{bundle: \Thelia\Model\Product, bike: \Thelia\Model\ProductBundleItem, helmet: \Thelia\Model\ProductBundleItem, lock: \Thelia\Model\ProductBundleItem, bikeProduct: \Thelia\Model\Product, helmetProduct: \Thelia\Model\Product, lockProduct: \Thelia\Model\Product}
     */
    private function example(BundlePriceMode $mode = BundlePriceMode::Computed): array
    {
        return $this->exampleBundle($this->factory(), $this->getService(BundleCompositionService::class), $mode);
    }

    private ?FixtureFactory $factory = null;

    private function factory(): FixtureFactory
    {
        return $this->factory ??= new FixtureFactory(Propel::getConnection('TheliaMain'));
    }
}
