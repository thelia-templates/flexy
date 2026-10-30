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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\LiveResponder;
use Symfony\UX\TwigComponent\ComponentFactory;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Catalog\Quantity\QuantityRule;
use Thelia\Domain\Catalog\Quantity\UnitCode;
use Thelia\Model\Cart as CartModel;
use Thelia\Model\CartItemQuery;
use Thelia\Model\CartQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Test\IntegrationTestCase;

/**
 * A line of 1.25 kg, sold by the quarter from half a kilogram, read off the session's cart: it
 * keeps its decimals and its rule, its field is a decimal field written the way the page writes
 * numbers, and its "minus" from half a kilogram takes the line out of the cart, as a "minus" on
 * a line of one always did. Runs on an installed shop whose core has quantity rules.
 */
final class CartMeasureLineTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        if (!class_exists(QuantityRule::class)) {
            self::markTestSkipped('This core has no quantity rules.');
        }

        parent::setUp();
    }

    public function testTheLineKeepsItsDecimalsAndItsRule(): void
    {
        $this->putInSession($this->cartWithAWeight(1.25));

        $item = $this->component()->items[0];

        self::assertSame(1.25, $item->quantity);
        self::assertSame(7.5, $item->stock);
        self::assertSame(2, $item->rule()->decimals);
        self::assertSame(0.25, $item->rule()->step);
        self::assertSame('kg', $item->rule()->unitCode);
    }

    public function testTheFieldOfTheLineTakesADecimalWrittenTheWayThePageWritesIt(): void
    {
        $this->putInSession($this->cartWithAWeight(1.25));
        $this->speak('fr_FR');

        // The tag as a whole: its data-action holds "->", which a [^>]* would stop at.
        self::assertSame(1, preg_match('/<input\s(?:"[^"]*"|[^>"])*>/', $this->renderLine(), $input));
        $input = $input[0];

        self::assertStringContainsString('type="text" inputmode="decimal"', $input);
        self::assertStringContainsString('value="1,25"', $input, 'the line shows its quantity with its two decimals, in French');
        self::assertStringContainsString('data-live-base-quantity-param="1.25"', $input, 'the component reads it back with a point');
        self::assertStringContainsString('change->Organisms--CartItem--quantity#normalize change->live#action', $input, 'the comma becomes a point before the component reads it');
    }

    public function testAMinusFromTheSmallestQuantityTakesTheLineOut(): void
    {
        $cart = $this->cartWithAWeight(0.5);
        $this->putInSession($cart);
        $component = $this->component();
        $component->setLiveResponder(new LiveResponder());

        $component->minus(0);

        self::assertSame(0, CartItemQuery::create()->filterByCartId($cart->getId())->count(), 'half a kilogram less a quarter is under the minimum: the line goes');
        self::assertSame(0.5, $component->pendingDelete['quantity'] ?? null, 'and the undo puts back half a kilogram');
    }

    private function cartWithAWeight(float $quantity): CartModel
    {
        $factory = $this->createFixtureFactory();
        $currency = $factory->currency();
        $product = $factory->product($factory->category(), $factory->taxRule(), $currency, ['ref' => 'WEIGHT-'.uniqid(), 'title' => 'Lexie', 'locale' => Lang::getDefaultLanguage()->getLocale(), 'basePrice' => 14.82, 'baseQuantity' => 10]);
        $saleElement = $product->getProductSaleElementss()->getFirst();
        \assert($saleElement instanceof ProductSaleElements);
        (new QuantityRule(minQuantity: 0.5, step: 0.25, decimals: 2, unitCode: UnitCode::KILOGRAM))->applyTo($saleElement);
        $saleElement->setQuantity(7.5)->save();

        $cart = $factory->cart(null, ['currency' => $currency]);
        $factory->cartItem($cart, $product, $saleElement, ['quantity' => $quantity]);

        return $cart;
    }

    private function renderLine(): string
    {
        $component = $this->component();
        // Assigned by the page parser only: a component rendered on its own has to be given it.
        static::getContainer()->get('twig')->addGlobal('lang_code', 'fr');
        $renderer = static::getContainer()->get('ux.twig_component.component_renderer');
        \assert($renderer instanceof ComponentRendererInterface);

        return $renderer->createAndRender('Organisms:CartItem:Base', ['cartItem' => $component->items[0], 'index' => 0]);
    }

    private function speak(string $locale): void
    {
        $lang = LangQuery::create()->filterByLocale($locale)->findOne();

        if (!$lang instanceof Lang) {
            self::markTestSkipped('The shop has no '.$locale.' language.');
        }

        $this->session()->setLang($lang);
    }

    private function putInSession(CartModel $cart): void
    {
        $this->session()->setSessionCart(CartQuery::create()->findPk($cart->getId()));
    }

    private function session(): Session
    {
        $stack = static::getContainer()->get('request_stack');
        \assert($stack instanceof RequestStack);
        $session = $stack->getCurrentRequest()?->getSession();
        \assert($session instanceof Session);

        return $session;
    }

    private function component(): Cart
    {
        $factory = static::getContainer()->get('ux.twig_component.component_factory');
        \assert($factory instanceof ComponentFactory);
        $component = $factory->create('Organisms:Cart:Base', [])->getComponent();
        \assert($component instanceof Cart);

        return $component;
    }
}
