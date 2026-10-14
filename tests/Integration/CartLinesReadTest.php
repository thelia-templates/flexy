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
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionWrapper;
use Propel\Runtime\Propel;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\ComponentFactory;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Cart as CartModel;
use Thelia\Model\CartQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The cart component reads the lines of a cart in a number of queries that does not grow with the cart (the cart page and the
 * cart step of the checkout render it), and titles a line in the language of the session, falling back to the default language of
 * the shop for a product not translated in it. Runs on an installed shop (bin/test-prepare).
 */
final class CartLinesReadTest extends IntegrationTestCase
{
    public function testTheLinesAreReadByANumberOfQueriesThatDoesNotGrowWithTheCart(): void
    {
        self::assertSame(...$this->queriesForOneAndFourLines());
    }

    public function testTheNumberOfQueriesDoesNotGrowWithTheCartWithTheInstancePoolOfTheShop(): void
    {
        // The test case turns the pool off so that every read reaches the database; the shop runs with it on.
        Propel::enableInstancePooling();

        try {
            [$one, $four] = $this->queriesForOneAndFourLines();

            self::assertSame($one, $four, 'one line or four, the same queries');
            // A cart of one line, the common one, read in one query (the combination) before: it must not cost more.
            self::assertLessThanOrEqual(1, $one);
        } finally {
            Propel::disableInstancePooling();
        }
    }

    public function testEachLineKeepsItsTitleAndItsStock(): void
    {
        $factory = $this->createFixtureFactory();
        $currency = $factory->currency();
        $category = $factory->category();
        $taxRule = $factory->taxRule();
        $locale = Lang::getDefaultLanguage()->getLocale();
        $cart = $factory->cart(null, ['currency' => $currency]);

        foreach ([1, 2, 3] as $n) {
            $factory->cartItem($cart, $factory->product($category, $taxRule, $currency, ['ref' => 'KEEP-'.$n, 'title' => 'Keep '.$n, 'locale' => $locale, 'basePrice' => 10.0, 'baseQuantity' => $n]));
        }

        $this->putInSession($cart);
        $component = $this->component();

        self::assertSame(['Keep 1', 'Keep 2', 'Keep 3'], array_map(static fn ($item): string => $item->title, $component->items));
        self::assertSame([1, 2, 3], array_map(static fn ($item): int => $item->stock, $component->items));
    }

    public function testTheTitleIsTheOneOfTheLanguageOfTheSession(): void
    {
        // The rule of the core (CartItem::getProduct) falls back to the default language when the setting is 1, its default.
        ConfigQuery::write('default_lang_without_translation', '1');
        $defaultLocale = Lang::getDefaultLanguage()->getLocale();
        $sessionLang = LangQuery::create()->filterByLocale($defaultLocale, Criteria::NOT_EQUAL)->filterByActive(1)->findOne();
        \assert($sessionLang instanceof Lang);
        $sessionLocale = $sessionLang->getLocale();

        $factory = $this->createFixtureFactory();
        $currency = $factory->currency();
        $category = $factory->category();
        $taxRule = $factory->taxRule();
        $translated = $factory->product($category, $taxRule, $currency, ['ref' => 'TITLE-1', 'title' => 'Session title', 'locale' => $sessionLocale, 'basePrice' => 10.0, 'baseQuantity' => 1]);
        $translated->setLocale($defaultLocale)->setTitle('Default title')->save();
        // Titled in the default language only: the session has nothing of its own to show.
        $untranslated = $factory->product($category, $taxRule, $currency, ['ref' => 'TITLE-2', 'title' => 'Only default', 'locale' => $defaultLocale, 'basePrice' => 10.0, 'baseQuantity' => 1]);
        $cart = $factory->cart(null, ['currency' => $currency]);
        $factory->cartItem($cart, $translated);
        $factory->cartItem($cart, $untranslated);

        $this->putInSession($cart);
        $this->session()->setLang($sessionLang);

        self::assertSame(['Session title', 'Only default'], array_map(static fn ($item): string => $item->title, $this->component()->items));
    }

    /**
     * The queries to read a cart of one line and a cart of four lines of different products.
     *
     * @return array{0: int, 1: int}
     */
    private function queriesForOneAndFourLines(): array
    {
        $factory = $this->createFixtureFactory();
        $currency = $factory->currency();
        $category = $factory->category();
        $taxRule = $factory->taxRule();
        $locale = Lang::getDefaultLanguage()->getLocale();
        $products = [];

        for ($n = 1; $n <= 5; ++$n) {
            $products[] = $factory->product($category, $taxRule, $currency, ['ref' => 'LINE-'.$n, 'title' => 'Line '.$n, 'locale' => $locale, 'basePrice' => 10.0 * $n, 'baseQuantity' => 10]);
        }

        $warm = $factory->cart(null, ['currency' => $currency]);
        $factory->cartItem($warm, array_pop($products));
        $small = $factory->cart(null, ['currency' => $currency]);
        $factory->cartItem($small, $products[0]);
        $large = $factory->cart(null, ['currency' => $currency]);

        foreach ($products as $product) {
            $factory->cartItem($large, $product);
        }

        // A first cart, of another product, warms what is read once for the request (the config, the lang).
        $this->queriesToRead($warm, 1);

        return [$this->queriesToRead($small, 1), $this->queriesToRead($large, 4)];
    }

    /**
     * The queries the component runs to read the lines of a cart put in session.
     */
    private function queriesToRead(CartModel $cart, int $expectedLines): int
    {
        $this->putInSession($cart);
        $component = $this->component();
        $connection = Propel::getConnection('TheliaMain');
        \assert($connection instanceof ConnectionWrapper);
        $connection->useDebug(true);
        $before = $connection->getQueryCount();

        $component->fetchCart();

        $count = $connection->getQueryCount() - $before;
        $connection->useDebug(false);

        self::assertCount($expectedLines, $component->items);

        return $count;
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
