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

namespace FlexyBundle\Tests\Component;

use FlexyBundle\Service\FormService;
use FlexyBundle\Service\ProductSort;
use FlexyBundle\Service\ShopPriceDisplay;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\ComponentFactory;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\ConfigQuery;

/**
 * A shop that shows its prices to nobody (`price_display_mode` = `on_request`, what a showcase
 * site sets) offers no price, no cart and no currency anywhere a visitor can go: the header, the
 * product cards, the product sheet, the filters and the sorts of a listing. Every case is played
 * with the prices shown too, so that what it leaves out is known to be there otherwise.
 */
final class ShopPriceDisplayTest extends KernelTestCase
{
    private ?string $storedMode = null;

    protected function setUp(): void
    {
        // The kernel first: it is what connects Propel.
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $this->storedMode = ConfigQuery::create()->findOneByName(ShopPriceDisplay::CONFIG_KEY)?->getValue();
        $request = Request::create('https://localhost/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');
    }

    protected function tearDown(): void
    {
        if (null === $this->storedMode) {
            ConfigQuery::create()->filterByName(ShopPriceDisplay::CONFIG_KEY)->delete();
        } else {
            ConfigQuery::write(ShopPriceDisplay::CONFIG_KEY, $this->storedMode);
        }

        ConfigQuery::resetCache();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function modes(): iterable
    {
        yield 'prices shown' => ['visible', true];
        yield 'prices on request' => ['on_request', false];
        yield 'a value the theme does not know' => ['typo', true];
    }

    #[DataProvider('modes')]
    public function testTheHeaderOffersTheCartOnlyWithThePrices(string $mode, bool $shown): void
    {
        $this->setMode($mode);

        $html = $this->render('Organisms:HeaderNav:Base');

        self::assertSame($shown, str_contains($html, 'Organisms:CartButton:Base'), 'cart button');
        self::assertSame($shown, str_contains($html, 'CurrencySelect'), 'currency selector');
        self::assertStringContainsString('LangSelect', $html, 'the rest of the header stays');
    }

    #[DataProvider('modes')]
    public function testACardTellsThePriceOnlyWhenItIsShown(string $mode, bool $shown): void
    {
        $this->setMode($mode);

        foreach (['Organisms:ProductCard:Base', 'Organisms:ProductCard:Search'] as $component) {
            $html = $this->render($component, ['product' => self::product()]);

            self::assertStringContainsString('Organic cotton t-shirt', $html, $component);
            self::assertSame($shown, str_contains($html, 'ProductCard-price'), $component);
        }

        // The search tile carries no badge: the rate of a discount is the card's.
        self::assertSame($shown, str_contains($this->render('Organisms:ProductCard:Base', ['product' => self::product()]), '-20%'), 'the rate of the discount');
    }

    public function testTheSelectorOffersNeitherPriceNorCartWithoutThePrices(): void
    {
        $shown = $this->renderSelector(true);
        self::assertStringContainsString('PseSelector-price', $shown);
        self::assertStringContainsString('Add to cart', $shown);

        $hidden = $this->renderSelector(false);
        self::assertStringNotContainsString('PseSelector-price', $hidden);
        self::assertStringNotContainsString('Add to cart', $hidden);
        self::assertStringNotContainsString('19.90', $hidden);
        self::assertStringContainsString('type="hidden"', $hidden, 'the quantity stays in the form, hidden');
    }

    public function testTheSheetRefusesAnAddWithoutThePrices(): void
    {
        $this->setMode('on_request');

        /** @var ComponentFactory $factory */
        $factory = self::getContainer()->get('ux.twig_component.component_factory');
        $sheet = $factory->get('Layouts:ProductDetails:Base');

        self::assertFalse($sheet->showsPrices());

        $sheet->save();

        self::assertTrue($sheet->cartRefused);
    }

    #[DataProvider('modes')]
    public function testAListingSortsByPriceOnlyWhenThePricesShow(string $mode, bool $shown): void
    {
        $this->setMode($mode);

        /** @var ProductSort $sorts */
        $sorts = self::getContainer()->get(ProductSort::class);
        $values = array_column($sorts->choices(), 'value');

        self::assertSame($shown, \in_array('asc', $values, true));
        self::assertSame($shown, \in_array('desc', $values, true));
        self::assertContains('newest', $values);
        self::assertSame($shown, \array_key_exists('untaxed_price_order', $sorts->parameters('asc')), 'a url still asking for it');
    }

    #[DataProvider('modes')]
    public function testAListingFiltersByPriceOnlyWhenThePricesShow(string $mode, bool $shown): void
    {
        $this->setMode($mode);

        /** @var FormFactoryInterface $forms */
        $forms = self::getContainer()->get('form.factory');
        $fieldset = $forms->createNamedBuilder('filters', FormType::class);

        /** @var FormService $formService */
        $formService = self::getContainer()->get(FormService::class);
        $formService->renderFieldFromFieldType([
            'id' => 'price',
            'type' => 'price',
            'title' => 'Price',
            'fieldType' => 'delta',
            'values' => [['id' => 1, 'title' => 10], ['id' => 2, 'title' => 90]],
        ], $fieldset, []);

        self::assertSame($shown, $fieldset->has('price'));
    }

    private function setMode(string $mode): void
    {
        ConfigQuery::write(ShopPriceDisplay::CONFIG_KEY, $mode);
        ConfigQuery::resetCache();
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(string $component, array $props = []): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender($component, $props);
    }

    private function renderSelector(bool $showsPrices): string
    {
        /** @var FormFactoryInterface $forms */
        $forms = self::getContainer()->get('form.factory');
        $form = $forms->createNamedBuilder('cart_add', FormType::class, null, ['csrf_protection' => false]);

        foreach (['product', 'product_sale_elements_id', 'append', 'newness', 'quantity'] as $field) {
            $form->add($field, HiddenType::class);
        }

        return $this->render('Organisms:PseSelector:Base', [
            'form' => $form->getForm()->createView(),
            'currentPse' => ['id' => 1, 'quantity' => 10, 'isPromo' => false, 'price' => 19.90, 'promoPrice' => 19.90],
            'showsPrices' => $showsPrices,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function product(): array
    {
        return [
            'id' => 99999,
            'ref' => 'REF-99999',
            'visible' => true,
            'position' => 1,
            'virtual' => false,
            'publicUrl' => '/organic-cotton-t-shirt',
            'i18ns' => ['title' => 'Organic cotton t-shirt'],
            'ProductColor' => ['colors' => []],
            'productCategories' => [],
            'productSaleElements' => [[
                'id' => 1,
                'ref' => 'REF-99999-1',
                'isDefault' => true,
                'promo' => true,
                'newness' => false,
                'quantity' => 10,
                'productPrices' => [['price' => 25.0, 'promoPrice' => 20.0, 'currency' => 'EUR']],
            ]],
        ];
    }
}
