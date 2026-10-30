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

use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\HttpFoundation\Session\Session;

/**
 * What the components of a bundle draw: the composition on the sheet (a list nothing can change, a tick, a bounded
 * quantity), the price block shared with the sale element selector, and the list of components under a line.
 */
final class BundleRenderTest extends KernelTestCase
{
    protected function setUp(): void
    {
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $request = Request::create('https://localhost/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');
    }

    public function testEachComponentGetsTheControlTheMerchantAllowed(): void
    {
        $html = $this->render('Organisms:BundleComposition:Base', ['components' => [
            ['itemId' => 1, 'title' => 'Mountain bike', 'imageId' => null, 'required' => true, 'quantity' => '1', 'control' => 'none', 'included' => true, 'minQuantity' => '1', 'maxQuantity' => '1'],
            ['itemId' => 3, 'title' => 'Chain lock', 'imageId' => null, 'required' => false, 'quantity' => '2', 'control' => 'quantity', 'included' => true, 'minQuantity' => '0', 'maxQuantity' => '3'],
            ['itemId' => 4, 'title' => 'Bell', 'imageId' => null, 'required' => false, 'quantity' => '0', 'control' => 'switch', 'included' => false, 'minQuantity' => '0', 'maxQuantity' => '1'],
        ]]);

        $items = $this->items($html);
        self::assertCount(3, $items);

        // Required and fixed: a quantity, no field at all.
        self::assertStringContainsString('Mountain bike', $items[0]);
        self::assertStringNotContainsString('<input', $items[0]);
        self::assertStringNotContainsString('Optional', $items[0]);

        // A bounded quantity, bound to the model of the sheet, with the molecule of the theme.
        self::assertStringContainsString('Optional', $items[1]);
        self::assertStringContainsString('data-model="on(change)|bundleSelection[3]"', $items[1]);
        self::assertStringContainsString('min="0"', $items[1]);
        self::assertStringContainsString('max="3"', $items[1]);
        self::assertStringContainsString('value="2"', $items[1]);
        self::assertStringContainsString('ButtonQuantity', $items[1]);

        // Either in or out: a tick that asks the sheet to toggle it, unticked here.
        self::assertStringContainsString('type="checkbox"', $items[2]);
        self::assertStringContainsString('data-live-action-param="toggleBundleItem"', $items[2]);
        self::assertStringContainsString('data-live-item-param="4"', $items[2]);
        self::assertDoesNotMatchRegularExpression('/\schecked(?:[\s=>])/', $items[2]);
    }

    public function testATickedSwitchIsChecked(): void
    {
        $html = $this->render('Organisms:BundleComposition:Base', ['components' => [
            ['itemId' => 4, 'title' => 'Bell', 'imageId' => null, 'required' => false, 'quantity' => '1', 'control' => 'switch', 'included' => true, 'minQuantity' => '0', 'maxQuantity' => '1'],
        ]]);

        self::assertMatchesRegularExpression('/\schecked(?:[\s=>])/', $this->items($html)[0]);
    }

    public function testTheMerchantWordingIsPrintedEscaped(): void
    {
        $html = $this->render('Organisms:BundleComposition:Base', ['components' => [
            ['itemId' => 1, 'title' => '<script>alert(1)</script>', 'imageId' => null, 'required' => true, 'quantity' => '1', 'control' => 'none', 'included' => true, 'minQuantity' => '1', 'maxQuantity' => '1'],
        ]]);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testASheetThatHasNoComponentDrawsNothing(): void
    {
        self::assertSame('', trim($this->render('Organisms:BundleComposition:Base', ['components' => []])));
    }

    public function testARefusedChoiceIsToldToTheShopper(): void
    {
        $component = ['itemId' => 1, 'title' => 'Mountain bike', 'imageId' => null, 'required' => true, 'quantity' => '1', 'control' => 'none', 'included' => true, 'minQuantity' => '1', 'maxQuantity' => '1'];

        self::assertStringContainsString('the default one is shown', $this->render('Organisms:BundleComposition:Base', ['components' => [$component], 'refused' => true]));
        self::assertStringNotContainsString('the default one is shown', $this->render('Organisms:BundleComposition:Base', ['components' => [$component]]));
    }

    public function testThePriceBlockStrikesTheOriginalPriceAndStatesTheSaving(): void
    {
        $html = $this->render('Molecules:Price:Base', ['price' => 648.0, 'originalPrice' => 720.0, 'saving' => 72.0]);

        self::assertStringContainsString('648.00', $html);
        self::assertMatchesRegularExpression('/Price-original[^>]*>\s*<span dir="ltr">[^<]*720\.00/', $html);
        self::assertStringContainsString('You save', $html);
        self::assertStringContainsString('72.00', $html);
    }

    public function testThePriceBlockSaysNothingOfASavingThatIsZeroOrAbsent(): void
    {
        foreach ([['price' => 100.0], ['price' => 100.0, 'saving' => 0.0], ['price' => 100.0, 'saving' => null, 'originalPrice' => null]] as $props) {
            $html = $this->render('Molecules:Price:Base', $props);

            self::assertStringNotContainsString('You save', $html);
            self::assertStringNotContainsString('Price-original', $html);
        }
    }

    public function testTheComponentsUnderALineHaveNeitherPriceNorControl(): void
    {
        $html = $this->render('Molecules:BundleIncluded:Base', ['components' => [['title' => 'Mountain bike', 'quantity' => '1'], ['title' => 'Helmet', 'quantity' => '2']]]);

        self::assertStringContainsString('Included in the bundle', $html);
        self::assertSame(2, substr_count($html, 'data-testid="bundle-included-item"'));
        self::assertStringNotContainsString('<input', $html);
        self::assertStringNotContainsString('<button', $html);
        self::assertStringNotContainsString('€', $html);
        self::assertSame('', trim($this->render('Molecules:BundleIncluded:Base', ['components' => []])));
    }

    #[IgnoreDeprecations]
    public function testTheSelectorShowsThePriceOfTheComposition(): void
    {
        $html = $this->renderSelector(['bundlePricing' => ['price' => 648.0, 'originalPrice' => 720.0, 'saving' => 72.0]]);

        self::assertStringContainsString('PseSelector-price', $html);
        self::assertStringContainsString('648.00', $html);
        self::assertStringContainsString('You save', $html);
        // The price of the sale element of the bundle (999 here) is not the one shown.
        self::assertStringNotContainsString('999.00', $html);
    }

    #[IgnoreDeprecations]
    public function testTheSelectorOfAProductKeepsItsPriceAndItsStrike(): void
    {
        $html = $this->renderSelector(['currentPse' => ['id' => 1, 'quantity' => 5, 'price' => 30.0, 'promoPrice' => 25.0, 'isPromo' => true, 'displayInitialPrice' => true]]);

        self::assertStringContainsString('25.00', $html);
        self::assertMatchesRegularExpression('/Price-original[^>]*>\s*<span dir="ltr">[^<]*30\.00/', $html);
        self::assertStringNotContainsString('You save', $html);
    }

    #[IgnoreDeprecations]
    public function testAnUnsellableBundleCannotBeAdded(): void
    {
        $html = $this->renderSelector(['bundleUnavailable' => true]);

        self::assertStringContainsString('This bundle is currently unavailable', $html);
        self::assertStringNotContainsString('Add to cart', $html);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(string $component, array $props): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender($component, $props);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function renderSelector(array $props): string
    {
        /** @var FormFactoryInterface $forms */
        $forms = self::getContainer()->get('form.factory');
        $form = $forms->createNamedBuilder('cart_add', FormType::class, null, ['csrf_protection' => false]);

        foreach (['product', 'product_sale_elements_id', 'append', 'newness', 'quantity'] as $field) {
            $form->add($field, HiddenType::class);
        }

        return $this->render('Organisms:PseSelector:Base', $props + [
            'form' => $form->getForm()->createView(),
            'currentPse' => ['id' => 1, 'quantity' => 5, 'price' => 999.0, 'promoPrice' => 999.0, 'isPromo' => false],
        ]);
    }

    /**
     * @return list<string>
     */
    private function items(string $html): array
    {
        preg_match_all('#<li class="BundleComposition-item.*?</li>#s', $html, $items);

        return $items[0];
    }
}
