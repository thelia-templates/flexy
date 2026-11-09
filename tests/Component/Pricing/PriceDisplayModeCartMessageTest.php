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

namespace FlexyBundle\Tests\Component\Pricing;

use Symfony\Component\BrowserKit\Cookie;
use Thelia\Domain\Pricing\PriceVisibility\PriceDisplayMode;
use Thelia\Model\CartItemQuery;
use Thelia\Model\Currency;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Integration\Domain\Pricing\PriceVisibility\PriceDisplayFixtures;

/**
 * A cart that comes back (found again by its cookie) with a line the shop no longer lets this
 * visitor buy, because the price of its product is now shown to nobody or to customers only, has
 * the line taken out, and the cart page tells the shopper once, in a sentence that names the
 * product and not the rule behind it.
 */
final class PriceDisplayModeCartMessageTest extends WebIntegrationTestCase
{
    use PriceDisplayFixtures;

    private const TOKEN = 'flexy-price-mode-cart-token';

    private FixtureFactory $factory;

    private Product $kept;

    private Product $closed;

    private int $cartId;

    protected function setUp(): void
    {
        parent::setUp();

        // Not createFixtureFactory(): it leaves a request on the stack, in front of the ones the client sends.
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $category = $this->factory->category();
        $taxRule = $this->factory->taxRule();
        $currency = Currency::getDefaultCurrency();

        $this->kept = $this->factory->product($category, $taxRule, $currency, ['title' => 'Kept product', 'basePrice' => 10.0, 'baseQuantity' => 50]);
        $this->closed = $this->factory->product($category, $taxRule, $currency, ['title' => 'Closed product', 'basePrice' => 20.0, 'baseQuantity' => 50]);

        $cart = $this->factory->cart(null, ['token' => self::TOKEN]);
        $this->factory->cartItem($cart, $this->kept);
        $this->factory->cartItem($cart, $this->closed);
        $this->cartId = (int) $cart->getId();
        $this->forgetPriceDisplayMemos();
    }

    public function testALineWhosePriceIsNowShownToNobodyIsTakenOutAndTheCartPageSaysSoWithoutNamingTheRule(): void
    {
        $this->productShows($this->closed, PriceDisplayMode::OnRequest);

        $page = $this->cartPage();

        $notice = $page->filter('.SnackBar--error')->text();
        self::assertStringContainsString('Closed product', $notice);
        self::assertStringNotContainsString('Kept product', $notice);
        self::assertDoesNotMatchRegularExpression('/on_request|on request|logged_in|price list|restriction/i', $notice);
        self::assertSame([(int) $this->kept->getId()], $this->productIdsOfTheCart());
        self::assertStringContainsString('Kept product', $page->text());
    }

    public function testALineOfAShopThatNowShowsItsPricesToCustomersOnlyIsTakenOutOfTheCartOfAVisitor(): void
    {
        $this->shopShows(PriceDisplayMode::LoggedIn);

        $page = $this->cartPage();

        self::assertCount(1, $page->filter('.SnackBar--error'));
        self::assertSame([], $this->productIdsOfTheCart());
    }

    public function testACartThatKeepsEveryLineSaysNothing(): void
    {
        $page = $this->cartPage();

        self::assertCount(0, $page->filter('.SnackBar--error'));
        self::assertCount(2, $this->productIdsOfTheCart());
    }

    private function cartPage(): \Symfony\Component\DomCrawler\Crawler
    {
        $this->client->getCookieJar()->set(new Cookie('thelia_cart', self::TOKEN));
        $this->assertPageRenders('/checkout/cart');

        return $this->client->getCrawler();
    }

    /**
     * @return list<int>
     */
    private function productIdsOfTheCart(): array
    {
        $ids = [];

        foreach (CartItemQuery::create()->filterByCartId($this->cartId)->orderById()->find() as $line) {
            $ids[] = (int) $line->getProductId();
        }

        return $ids;
    }
}
