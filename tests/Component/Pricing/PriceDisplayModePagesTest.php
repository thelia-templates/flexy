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

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Domain\Pricing\PriceVisibility\PriceDisplayMode;
use Thelia\Model\Currency;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Integration\Domain\Pricing\PriceVisibility\PriceDisplayFixtures;
use Thelia\Tests\Support\Flexy\CustomerSessionInjector;

/**
 * What a buyer reads on the listing and on the product sheet when the shop, or one of its products,
 * decides who is shown the prices.
 *
 *   logged_in   a visitor reads no price and no add to cart, an invitation to sign in that comes back
 *               to the page; a customer reads both
 *   on_request  nobody reads the price: a button leads to the contact page with the references of the
 *               item and no amount, and nobody has an add to cart
 *
 * The sentences are the ones of the theme, the facts are the core's (`priceDisplayMode`,
 * `purchasable`, the price absent when it is not shown).
 */
final class PriceDisplayModePagesTest extends WebIntegrationTestCase
{
    use PriceDisplayFixtures;

    private const CATEGORY_URL = 'flexy-price-mode-category.html';

    private const PRODUCT_URL = 'flexy-price-mode-product.html';

    private const PROMO_URL = 'flexy-price-mode-promo.html';

    private FixtureFactory $factory;

    private CustomerSessionInjector $injector;

    private Product $product;

    private Product $promo;

    protected function setUp(): void
    {
        parent::setUp();

        // Not createFixtureFactory(): it leaves a request on the stack, in front of the ones the client sends.
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->injector = new CustomerSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $category = $this->factory->category();
        $category->setLocale('en_US')->setTitle('Price mode category')->save($this->getPropelConnection());
        $category->setRewrittenUrl('en_US', self::CATEGORY_URL);
        $taxRule = $this->factory->taxRule();
        $currency = Currency::getDefaultCurrency();

        $this->product = $this->factory->product($category, $taxRule, $currency, ['ref' => 'MODE-1', 'basePrice' => 41.0, 'baseQuantity' => 50]);
        $this->product->setLocale('en_US')->setTitle('Price mode product')->save($this->getPropelConnection());
        $this->product->setRewrittenUrl('en_US', self::PRODUCT_URL);

        // On promotion: the discount tag of its card is computed out of the two prices.
        $this->promo = $this->factory->product($category, $taxRule, $currency, ['ref' => 'MODE-PROMO', 'basePrice' => 59.0, 'baseQuantity' => 50]);
        $this->promo->getDefaultSaleElements()->setPromo(1)->save($this->getPropelConnection());
        $this->promo->setLocale('en_US')->setTitle('Price mode promo product')->save($this->getPropelConnection());
        $this->promo->setRewrittenUrl('en_US', self::PROMO_URL);
        $this->forgetPriceDisplayMemos();
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);

        parent::tearDown();
    }

    public function testAVisitorOfAShopThatShowsItsPricesToCustomersOnlyReadsNoPriceAndHasNoAddToCart(): void
    {
        $this->shopShows(PriceDisplayMode::LoggedIn);

        $sheet = $this->page('/'.self::PRODUCT_URL);
        $block = $sheet->filter('.PseSelector-price');

        self::assertSame('hidden', $block->attr('data-price-state'));
        self::assertStringContainsString('Log in to see the prices', $block->text());
        self::assertDoesNotMatchRegularExpression('/\d+\.\d{2}/', $block->text());
        self::assertCount(0, $sheet->filter('.PseSelector-addToCart'));
        self::assertCount(0, $sheet->filter('button[type="submit"]'));
    }

    public function testTheInvitationToSignInComesBackToTheProductSheet(): void
    {
        $this->shopShows(PriceDisplayMode::LoggedIn);

        $href = (string) $this->page('/'.self::PRODUCT_URL)->filter('[data-testid="price-login"]')->attr('href');

        self::assertStringStartsWith('/customer/login?', $href);
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);
        self::assertSame('/'.self::PRODUCT_URL, $query['redirect']);
    }

    public function testTheListingOfAShopThatShowsItsPricesToCustomersOnlyInvitesAVisitorToSignInInPlaceOfEveryPrice(): void
    {
        $this->shopShows(PriceDisplayMode::LoggedIn);

        $card = $this->cardOf($this->page('/'.self::CATEGORY_URL), 'Price mode product');

        self::assertSame('hidden', $card->filter('.PriceBlock')->attr('data-price-state'));
        self::assertDoesNotMatchRegularExpression('/\d+\.\d{2}/', $card->text());
    }

    public function testACustomerOfAShopThatShowsItsPricesToCustomersOnlyReadsThePriceAndHasTheAddToCart(): void
    {
        $this->shopShows(PriceDisplayMode::LoggedIn);
        $this->injector->setCustomer($this->factory->customer($this->factory->customerTitle()));

        $sheet = $this->page('/'.self::PRODUCT_URL);

        self::assertSame('price', $sheet->filter('.PseSelector-price')->attr('data-price-state'));
        self::assertMatchesRegularExpression('/\d+\.\d{2}/', $sheet->filter('.PseSelector-price')->text());
        self::assertCount(1, $sheet->filter('.PseSelector-addToCart'));

        $card = $this->cardOf($this->page('/'.self::CATEGORY_URL), 'Price mode product');
        self::assertSame('price', $card->filter('.PriceBlock')->attr('data-price-state'));
    }

    public function testAProductOnRequestOffersTheRequestToAVisitorAndToACustomerAndHasNoAddToCart(): void
    {
        $this->productShows($this->product, PriceDisplayMode::OnRequest);

        foreach ([false, true] as $signedIn) {
            if ($signedIn) {
                $this->injector->setCustomer($this->factory->customer($this->factory->customerTitle()));
            }

            $sheet = $this->page('/'.self::PRODUCT_URL);
            $block = $sheet->filter('.PseSelector-price');

            self::assertSame('on_request', $block->attr('data-price-state'), $signedIn ? 'customer' : 'visitor');
            self::assertStringContainsString('Request a price', $block->text());
            self::assertDoesNotMatchRegularExpression('/\d+\.\d{2}/', $block->text());
            self::assertCount(0, $sheet->filter('.PseSelector-addToCart'));
        }
    }

    public function testTheRequestLeadsToTheContactPageWithTheReferencesAndNoAmount(): void
    {
        $this->productShows($this->product, PriceDisplayMode::OnRequest);

        $href = (string) $this->page('/'.self::PRODUCT_URL)->filter('[data-testid="price-request"]')->attr('href');

        self::assertSame('/contact?product_ref='.rawurlencode((string) $this->product->getRef()).'&pse_ref='.rawurlencode((string) $this->product->getDefaultSaleElements()->getRef()), $href, 'The references of the product and of the combination, no amount.');
        self::assertDoesNotMatchRegularExpression('/\d+\.\d{2}/', $href);
    }

    public function testTheCardOfAProductOnRequestOffersTheRequestToo(): void
    {
        $this->productShows($this->product, PriceDisplayMode::OnRequest);

        $card = $this->cardOf($this->page('/'.self::CATEGORY_URL), 'Price mode product');

        self::assertSame('on_request', $card->filter('.PriceBlock')->attr('data-price-state'));
        self::assertCount(1, $card->filter('[data-testid="price-request"]'));
        self::assertDoesNotMatchRegularExpression('/\d+\.\d{2}/', $card->text());
    }

    public function testAProductOnPromotionWhosePriceIsNotShownHasNeitherPriceNorDiscountTagOnItsCard(): void
    {
        $this->productShows($this->promo, PriceDisplayMode::OnRequest);

        $card = $this->cardOf($this->page('/'.self::CATEGORY_URL), 'Price mode promo product');

        self::assertCount(0, $card->filter('.ProductCard-price'));
        self::assertStringNotContainsString('%', $card->text());
    }

    public function testABuyerReadingUntaxedPricesIsNotShownAZeroInPlaceOfAPriceThatIsNotShown(): void
    {
        $this->productShows($this->product, PriceDisplayMode::OnRequest);
        $this->injector->setCustomer($this->factory->customer($this->factory->customerTitle(), ['customerGroup' => $this->factory->customerGroup('untaxed-mode', 0.0, false)]));

        $card = $this->cardOf($this->page('/'.self::CATEGORY_URL), 'Price mode product');

        self::assertCount(0, $card->filter('.ProductCard-price'));
        self::assertStringNotContainsString('0.00', $card->text());
        self::assertSame('on_request', $card->filter('.PriceBlock')->attr('data-price-state'));
    }

    public function testAProductMayShowItsPriceInAShopThatDoesNot(): void
    {
        $this->shopShows(PriceDisplayMode::LoggedIn);
        $this->productShows($this->product, PriceDisplayMode::Visible);

        $sheet = $this->page('/'.self::PRODUCT_URL);

        self::assertSame('price', $sheet->filter('.PseSelector-price')->attr('data-price-state'));
        self::assertCount(1, $sheet->filter('.PseSelector-addToCart'));
    }

    public function testAShopThatShowsEveryPriceKeepsItsPagesAsTheyWere(): void
    {
        $sheet = $this->page('/'.self::PRODUCT_URL);

        self::assertSame('price', $sheet->filter('.PseSelector-price')->attr('data-price-state'));
        self::assertCount(0, $sheet->filter('[data-testid="price-login"], [data-testid="price-request"]'));
        self::assertCount(1, $sheet->filter('.PseSelector-addToCart'));
    }

    private function page(string $url): Crawler
    {
        $this->assertPageRenders($url);

        return $this->client->getCrawler();
    }

    private function cardOf(Crawler $page, string $title): Crawler
    {
        $titled = $page->filter('.ProductCard')->reduce(static fn (Crawler $card): bool => '' !== $card->filter('.ProductCard-title')->count() && trim($card->filter('.ProductCard-title')->first()->text()) === $title);
        self::assertCount(1, $titled, \sprintf('"%s" has one card.', $title));

        return $titled->first();
    }
}
