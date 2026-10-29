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
use FlexyBundle\Service\CartStockService;
use FlexyBundle\Tests\Support\BuildsBundles;
use Propel\Runtime\Propel;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\LiveResponder;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Api\Service\DataAccess\AttributeAccessService;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Catalog\Bundle\BundleComposition;
use Thelia\Domain\Catalog\Bundle\BundleCompositionService;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\Cart as CartModel;
use Thelia\Model\CartItem;
use Thelia\Model\CartItemQuery;
use Thelia\Model\CartQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The cart page and a bundle: the line lists what the bundle holds, without price or control, and its stock is
 * what its components allow (the sale element of a bundle holds nothing, and reading it would flag every bundle
 * as gone); a removed bundle comes back with the composition it had. Runs on an installed shop (bin/test-prepare).
 */
final class CartBundleLinesTest extends IntegrationTestCase
{
    use BuildsBundles;

    private ?FixtureFactory $factory = null;

    public function testABundleLineListsItsComponentsForOneBundle(): void
    {
        [$example] = $this->cartWithABundle();

        $item = $this->cart()->items[0];

        self::assertSame(
            [['title' => 'Mountain bike', 'quantity' => '1'], ['title' => 'Helmet', 'quantity' => '2'], ['title' => 'Chain lock', 'quantity' => '2']],
            $item->bundleComponents,
        );
        self::assertNotNull($example);
    }

    public function testTheStockOfABundleLineIsWhatItsComponentsAllow(): void
    {
        ConfigQuery::write('check-available-stock', '1');
        $this->cartWithABundle();
        $cart = $this->cart();

        // min(7, floor(5 / 2), floor(10 / 2)) = 2; the sale element of the bundle holds 0.
        self::assertSame(2, $cart->items[0]->stock);
        self::assertTrue($cart->items[0]->stockManaged);
        self::assertFalse($cart->itemHasNoStockMessage);
        self::assertFalse($cart->itemHasInsufficientStockMessage);
    }

    public function testABundleWhoseComponentRanOutIsFlaggedAsGone(): void
    {
        ConfigQuery::write('check-available-stock', '1');
        [$example] = $this->cartWithABundle();
        Propel::getConnection('TheliaMain')->exec(\sprintf('UPDATE product_sale_elements SET quantity = 1 WHERE id = %d', $this->saleElementsIdOf($example['helmetProduct'])));

        $cart = $this->cart();

        self::assertSame(0, $cart->items[0]->stock);
        self::assertTrue($cart->itemHasNoStockMessage);
    }

    public function testABundleOfVirtualComponentsHasNoStockToEnforce(): void
    {
        ConfigQuery::write('check-available-stock', '1');
        [$example] = $this->cartWithABundle();
        foreach (['bikeProduct', 'helmetProduct', 'lockProduct'] as $key) {
            Propel::getConnection('TheliaMain')->exec(\sprintf('UPDATE product SET `virtual` = 1 WHERE id = %d', $example[$key]->getId()));
        }

        $cart = $this->cart();

        self::assertFalse($cart->items[0]->stockManaged);
        self::assertFalse($cart->itemHasNoStockMessage);
    }

    public function testTheStockOfEveryLineIsReadInOnePassWhateverTheNumberOfBundleLines(): void
    {
        ConfigQuery::write('check-available-stock', '1');
        $this->cartWithABundle();
        $line = CartItemQuery::create()->findOne();
        $saleElements = $line->getProductSaleElements();
        $service = $this->getService(CartStockService::class);
        $service->stocksOf([[$line, $saleElements]]);

        $queries = static function (callable $work): int {
            $connection = Propel::getConnection('TheliaMain');
            $connection->useDebug(true);
            $before = $connection->getQueryCount();
            $work();

            return $connection->getQueryCount() - $before;
        };
        $one = $queries(static fn () => $service->stocksOf([[$line, $saleElements]]));
        $answers = [];
        $four = $queries(static function () use ($service, $line, $saleElements, &$answers): void {
            $answers = $service->stocksOf([1 => [$line, $saleElements], 2 => [$line, $saleElements], 3 => [$line, $saleElements], 4 => [$line, $saleElements]]);
        });

        self::assertSame($one, $four, 'A composition shared by four lines is asked of the core once.');
        self::assertSame($service->stockOf($line, $saleElements), $answers[3]);
        self::assertSame(2, $answers[1]['stock'], 'min(7, 5 / 2, 10 / 2) bundles: the helmets decide.');
    }

    public function testALineThatIsNotABundleIsReadAsBefore(): void
    {
        ConfigQuery::write('check-available-stock', '1');
        $factory = $this->factory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['title' => 'Plain', 'baseQuantity' => 3]);
        $cartModel = $this->putACartInSession();
        $factory->cartItem($cartModel, $product);

        $item = $this->cart()->items[0];

        self::assertSame([], $item->bundleComponents);
        self::assertSame(3, $item->stock, 'the stock of the sale element, as for any product');
    }

    public function testARemovedBundleComesBackWithTheCompositionItHad(): void
    {
        ConfigQuery::write('check-available-stock', '1');
        [$example, $cartModel] = $this->cartWithABundle();
        $component = $this->cart();

        $component->remove(0);

        self::assertSame(0, CartItemQuery::create()->filterByCartId($cartModel->getId())->count());
        self::assertSame(self::composition($example, 2)->toCanonicalJson(), $component->pendingDelete['bundleComposition'] ?? null);

        $component->restoreCartItem($this->saleElementsIdOf($example['bundle']), (int) $example['bundle']->getId(), 1);

        $restored = CartItemQuery::create()->filterByCartId($cartModel->getId())->findOne();
        self::assertNotNull($restored);
        self::assertSame(self::composition($example, 2)->toCanonicalJson(), $restored->getBundleComposition(), 'two locks, not the default composition');
        self::assertNull($component->pendingDelete);
    }

    public function testABundleIsNotPutBackWhenItsComponentsCannotSupplyIt(): void
    {
        ConfigQuery::write('check-available-stock', '1');
        [$example, $cartModel] = $this->cartWithABundle();
        $component = $this->cart();
        $component->remove(0);
        Propel::getConnection('TheliaMain')->exec(\sprintf('UPDATE product_sale_elements SET quantity = 1 WHERE id = %d', $this->saleElementsIdOf($example['helmetProduct'])));

        $component->restoreCartItem($this->saleElementsIdOf($example['bundle']), (int) $example['bundle']->getId(), 1);

        self::assertSame(0, CartItemQuery::create()->filterByCartId($cartModel->getId())->count(), 'the stock of the bundle sale element is 0 and was never the answer: the components are');
        self::assertNull($component->pendingDelete);
    }

    public function testTheLineRendersItsComponentsWithoutAnyPriceOfTheirOwn(): void
    {
        $this->cartWithABundle();
        $item = $this->cart()->items[0];
        static::getContainer()->get('twig')->addGlobal('lang_code', 'en');

        $renderer = static::getContainer()->get('ux.twig_component.component_renderer');
        \assert($renderer instanceof ComponentRendererInterface);
        $html = $renderer->createAndRender('Organisms:CartItem:Base', ['cartItem' => $item, 'index' => 0]);

        self::assertStringContainsString('data-testid="bundle-included"', $html);
        self::assertStringContainsString('Included in the bundle', $html);
        self::assertSame(3, substr_count($html, 'data-testid="bundle-included-item"'));
        self::assertStringContainsString('Chain lock', $html);
        // The list prints a title and a quantity: no currency sign inside it.
        preg_match('#data-testid="bundle-included".*?</ul>#s', $html, $list);
        self::assertStringNotContainsString('€', $list[0] ?? '');
        self::assertStringNotContainsString('$', $list[0] ?? '');
    }

    public function testABundleTheCatalogueStillHoldsIsOrderableAndKeepsItsPrice(): void
    {
        $this->cartWithABundle();
        $item = $this->cart()->items[0];

        self::assertTrue($item->bundleOrderable);

        $html = $this->renderLine($item);

        self::assertStringNotContainsString('data-cart-item-unorderable', $html);
        self::assertStringContainsString('CartItem-price', $html);
        self::assertStringContainsString('648.00', $html);
        self::assertStringContainsString('data-testid="bundle-included"', $html);
    }

    public function testABundleTheCatalogueNoLongerHoldsIsToldOnItsLineWithNoPriceAndNoList(): void
    {
        [$example] = $this->cartWithABundle();
        // A required component is deleted after the pack went into the cart: the stored price is the one of a pack that is gone.
        $example['helmetProduct']->delete();

        $item = $this->cart()->items[0];

        self::assertFalse($item->bundleOrderable);

        $html = $this->renderLine($item);

        self::assertStringContainsString('data-cart-item-unorderable="true"', $html);
        self::assertStringContainsString(\sprintf('The pack %s can no longer be ordered as it is in your cart. Please remove it and add it again.', $example['bundle']->getRef()), $html);
        self::assertStringNotContainsString('CartItem-price', $html, 'No price is shown as valid.');
        self::assertStringNotContainsString('648.00', $html);
        self::assertStringNotContainsString('data-testid="bundle-included"', $html, 'The list of what is left is not the pack the shopper bought.');
        self::assertStringNotContainsString('data-live-action-param="plus"', $html, 'The quantity of a pack that cannot be ordered is not changed.');
        self::assertStringContainsString('Delete', $html, 'The way out is still there.');
    }

    public function testTheLineSaysTheRefusalTheCoreWordsAndFallsBackOnTheThemeSentence(): void
    {
        [$example] = $this->cartWithABundle();
        $item = $this->cart()->items[0];
        $item->bundleOrderable = false;

        $item->bundleRefusal = 'Core wording of the refusal.';
        $withCoreWording = $this->renderLine($item);

        self::assertStringContainsString('Core wording of the refusal.', $withCoreWording);
        self::assertStringNotContainsString('can no longer be ordered as it is in your cart', $withCoreWording);

        $item->bundleRefusal = '';
        $withoutCoreWording = $this->renderLine($item);

        self::assertStringContainsString(\sprintf('The pack %s can no longer be ordered as it is in your cart.', $example['bundle']->getRef()), $withoutCoreWording);
    }

    public function testTheRefusalOfTheCoreTravelsFromTheCartToTheLine(): void
    {
        [$example] = $this->cartWithABundle();
        $example['helmetProduct']->delete();

        $item = $this->cart()->items[0];

        self::assertFalse($item->bundleOrderable);
        self::assertStringContainsString((string) $example['bundle']->getRef(), $item->bundleRefusal);
    }

    public function testACoreThatPredatesTheVerdictLeavesEveryLineOrderable(): void
    {
        self::assertTrue(CartItemDto::fromArray(['id' => 1])->bundleOrderable);
        self::assertFalse(CartItemDto::fromArray(['id' => 1, 'bundleOrderable' => false])->bundleOrderable);
    }

    private function renderLine(CartItemDto $item): string
    {
        static::getContainer()->get('twig')->addGlobal('lang_code', 'en');
        $renderer = static::getContainer()->get('ux.twig_component.component_renderer');
        \assert($renderer instanceof ComponentRendererInterface);

        return $renderer->createAndRender('Organisms:CartItem:Base', ['cartItem' => $item, 'index' => 0]);
    }

    /**
     * @return array{0: array<string, mixed>, 1: CartModel}
     */
    private function cartWithABundle(): array
    {
        $factory = $this->factory();
        $example = $this->exampleBundle($factory, $this->getService(BundleCompositionService::class));
        $cartModel = $this->putACartInSession();

        // Written whole, composition included: a line of a bundle that says nothing of its composition is refused.
        (new CartItem())
            ->setCartId($cartModel->getId())
            ->setProductId($example['bundle']->getId())
            ->setProductSaleElementsId($this->saleElementsIdOf($example['bundle']))
            ->setQuantity(1)
            ->setPrice('600.000000')
            ->setPromoPrice('540.000000')
            ->setPromo(1)
            ->setIsOffered(0)
            ->setBundleComposition(self::composition($example, 2)->toCanonicalJson())
            ->save();

        return [$example, $cartModel];
    }

    /**
     * @param array<string, mixed> $example
     */
    private static function composition(array $example, int $locks): BundleComposition
    {
        return BundleComposition::fromArray([
            ['bundleItemId' => $example['bike']->getId(), 'quantity' => 1],
            ['bundleItemId' => $example['helmet']->getId(), 'quantity' => 2],
            ['bundleItemId' => $example['lock']->getId(), 'quantity' => $locks],
        ]);
    }

    private function putACartInSession(): CartModel
    {
        $factory = $this->factory();
        $cart = $factory->cart(null, ['currency' => $factory->currency()]);
        $stack = static::getContainer()->get('request_stack');
        \assert($stack instanceof RequestStack);
        $session = $stack->getCurrentRequest()?->getSession();
        \assert($session instanceof Session);
        $cartModel = CartQuery::create()->findPk($cart->getId());
        \assert($cartModel instanceof CartModel);
        $session->setSessionCart($cartModel);

        return $cartModel;
    }

    private function cart(): Cart
    {
        $component = new Cart(
            $this->getService(CartFacade::class),
            $this->getService(FormServiceInterface::class),
            $this->getService(CartStockService::class),
            $this->getService(AttributeAccessService::class),
            $this->getService(LangService::class),
        );
        $component->setLiveResponder(new LiveResponder());
        $component->mount();

        return $component;
    }

    private function factory(): FixtureFactory
    {
        return $this->factory ??= new FixtureFactory(Propel::getConnection('TheliaMain'));
    }
}
