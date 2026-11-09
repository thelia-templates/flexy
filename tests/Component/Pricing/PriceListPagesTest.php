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
use Thelia\Domain\Cart\Service\CartLinePricer;
use Thelia\Domain\Catalog\Restriction\CatalogRestrictionMode;
use Thelia\Model\CatalogRestriction;
use Thelia\Model\Category;
use Thelia\Model\Currency;
use Thelia\Model\Customer;
use Thelia\Model\CustomerGroup;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Integration\Domain\Pricing\PriceList\PriceListFixtures;
use Thelia\Tests\Support\Flexy\CustomerSessionInjector;

/**
 * What the buyers of a shop with price lists read on the pages of the theme: the price their list
 * gives them in the listing, on the product sheet and in the cart, the catalog price when no list
 * applies to them, the tiers of the list on the sheet, a catalog narrowed to what they may buy, and
 * an item an exclusive list does not sell them shown but not sold.
 *
 * The shop of the scenario (default currency, prices without taxes for the two groups read):
 *
 *   A catalog 100   list PRO (group pro): A 80
 *   T catalog 12    list PRO: T 1 -> 10, T 10 -> 9
 *   X catalog 20    denied to group pro (a product rule)
 *   B catalog 30    exclusive list EXCL (group excl) holds A only: B is not sold to group excl
 */
final class PriceListPagesTest extends WebIntegrationTestCase
{
    use PriceListFixtures;

    private const CATEGORY_URL = 'flexy-price-list-category.html';

    private FixtureFactory $factory;

    private CustomerSessionInjector $injector;

    private CustomerGroup $pro;

    private CustomerGroup $plain;

    private CustomerGroup $exclusive;

    /** @var array<string, Product> */
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Not createFixtureFactory(): it leaves a request on the stack, in front of the ones the client sends.
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->injector = new CustomerSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $category = $this->factory->category();
        $category->setLocale('en_US')->setTitle('Price list category')->save($this->getPropelConnection());
        $category->setRewrittenUrl('en_US', self::CATEGORY_URL);
        $taxRule = $this->factory->taxRule();
        $currency = Currency::getDefaultCurrency();

        foreach (['A' => 100.0, 'T' => 12.0, 'X' => 20.0, 'B' => 30.0] as $letter => $price) {
            $this->products[$letter] = $this->product($category, $taxRule, $currency, $letter, $price);
        }

        $this->pro = $this->factory->customerGroup('pro-list', 0.0, false);
        $this->plain = $this->factory->customerGroup('plain-list', 0.0, false);
        $this->exclusive = $this->factory->customerGroup('excl-list', 0.0, false);

        $list = $this->priceList('PRO', priority: 10);
        $this->assignToGroup($list, $this->pro);
        $this->priceListItem($list, $this->pse('A'), '80.000000');
        $this->priceListItem($list, $this->pse('T'), '10.000000');
        $this->priceListItem($list, $this->pse('T'), '9.000000', 10);

        $exclusiveList = $this->priceList('EXCL', priority: 10, exclusive: true);
        $this->assignToGroup($exclusiveList, $this->exclusive);
        $this->priceListItem($exclusiveList, $this->pse('A'), '70.000000');

        (new CatalogRestriction())
            ->setMode(CatalogRestrictionMode::Deny->value)
            ->setCustomerGroupId($this->pro->getId())
            ->setProductId($this->products['X']->getId())
            ->save($this->getPropelConnection());

        $this->forgetRequestMemos();
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);

        parent::tearDown();
    }

    public function testACustomerOfTheGroupWithAPriceListReadsItsPriceInTheListingTheSheetAndTheCart(): void
    {
        $customer = $this->signedIn($this->pro);

        $listing = $this->page('/'.self::CATEGORY_URL);
        self::assertStringContainsString('80.00', $this->cardOf($listing, 'Price list product A')->filter('.ProductCard-price')->text());
        self::assertStringNotContainsString('100.00', $this->cardOf($listing, 'Price list product A')->text());

        $sheet = $this->page('/'.$this->url('A'));
        self::assertStringContainsString('80.00', $sheet->filter('.PseSelector-price')->text());
        self::assertStringNotContainsString('100.00', $sheet->filter('.PseSelector-price')->text());

        $this->cartOf($customer, 'A');
        $cart = $this->page('/checkout/cart');
        self::assertStringContainsString('80.00', $cart->filter('.CartItem-price')->first()->text());
    }

    public function testACustomerOfAGroupWithoutAPriceListReadsTheCatalogPrice(): void
    {
        $customer = $this->signedIn($this->plain);

        self::assertStringContainsString('100.00', $this->cardOf($this->page('/'.self::CATEGORY_URL), 'Price list product A')->filter('.ProductCard-price')->text());
        self::assertStringContainsString('100.00', $this->page('/'.$this->url('A'))->filter('.PseSelector-price')->text());

        $this->cartOf($customer, 'A');
        self::assertStringContainsString('100.00', $this->page('/checkout/cart')->filter('.CartItem-price')->first()->text());
    }

    public function testTheSheetListsTheTiersOfThePriceListOfTheCustomer(): void
    {
        $this->signedIn($this->pro);

        $rows = $this->page('/'.$this->url('T'))->filter('[data-testid="price-tiers"] li')->each(fn (Crawler $row): string => $this->normalized($row->text()));

        self::assertSame(['From 1: €10.00 excl. tax', 'From 10: €9.00 excl. tax'], $rows);
    }

    public function testTheListingShowsTheFirstTierAndNoTable(): void
    {
        $this->signedIn($this->pro);

        $card = $this->cardOf($this->page('/'.self::CATEGORY_URL), 'Price list product T');

        self::assertStringContainsString('10.00', $card->filter('.ProductCard-price')->text());
        self::assertCount(0, $card->filter('[data-testid="price-tiers"]'));
    }

    public function testASheetWhoseOnlyTierStartsAtOneUnitHasNoTable(): void
    {
        $this->signedIn($this->pro);

        self::assertCount(0, $this->page('/'.$this->url('A'))->filter('[data-testid="price-tiers"]'));
    }

    public function testAProductOutsideTheAssortmentIsAbsentFromTheListingAndTheSearchAndNotFoundWhenAskedFor(): void
    {
        $this->signedIn($this->pro);

        $listing = $this->page('/'.self::CATEGORY_URL);
        self::assertCount(0, $this->cardsTitled($listing, 'Price list product X'));
        self::assertCount(1, $this->cardsTitled($listing, 'Price list product A'));

        $search = $this->page('/search?query='.rawurlencode('Price list product'));
        self::assertCount(0, $this->cardsTitled($search, 'Price list product X'));
        self::assertGreaterThan(0, $this->cardsTitled($search, 'Price list product A')->count(), 'The search still finds what the customer may buy.');

        $this->client->request('GET', '/'.$this->url('X'));
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnotherGroupStillSeesTheWholeAssortment(): void
    {
        $this->signedIn($this->plain);

        self::assertCount(1, $this->cardsTitled($this->page('/'.self::CATEGORY_URL), 'Price list product X'));
        $this->assertPageRenders('/'.$this->url('X'));
    }

    public function testAnItemAnExclusivePriceListDoesNotSellIsShownAtItsPublicPriceWithTheMentionAndWithoutAddToCart(): void
    {
        $this->signedIn($this->exclusive);

        $sheet = $this->page('/'.$this->url('B'));
        self::assertSame('unavailable', $sheet->filter('.PseSelector-price')->attr('data-price-state'));
        self::assertStringContainsString('30.00', $sheet->filter('.PseSelector-price')->text());
        self::assertStringContainsString('Not available for purchase', $sheet->filter('.PseSelector-price')->text());
        self::assertCount(0, $sheet->filter('.PseSelector-price [data-testid="price-tiers"]'));
        self::assertCount(0, $sheet->filter('.PseSelector-addToCart'));

        $card = $this->cardOf($this->page('/'.self::CATEGORY_URL), 'Price list product B');
        self::assertStringContainsString('30.00', $card->filter('.ProductCard-price')->text());
        self::assertStringContainsString('Not available for purchase', $card->text());
    }

    public function testTheItemsTheExclusiveListHoldsAreSoldAtItsPrice(): void
    {
        $this->signedIn($this->exclusive);

        $sheet = $this->page('/'.$this->url('A'));

        self::assertStringContainsString('70.00', $sheet->filter('.PseSelector-price')->text());
        self::assertCount(1, $sheet->filter('.PseSelector-addToCart'));
    }

    private function product(Category $category, \Thelia\Model\TaxRule $taxRule, Currency $currency, string $letter, float $price): Product
    {
        $product = $this->factory->product($category, $taxRule, $currency, ['ref' => 'PL-'.$letter, 'basePrice' => $price, 'baseQuantity' => 50]);
        $product->setLocale('en_US')->setTitle('Price list product '.$letter)->save($this->getPropelConnection());
        $product->setRewrittenUrl('en_US', 'flexy-price-list-'.strtolower($letter).'.html');

        return $product;
    }

    private function url(string $letter): string
    {
        return 'flexy-price-list-'.strtolower($letter).'.html';
    }

    private function pse(string $letter): \Thelia\Model\ProductSaleElements
    {
        return $this->products[$letter]->getDefaultSaleElements();
    }

    private function signedIn(CustomerGroup $group): Customer
    {
        $customer = $this->memberOf($group);
        $this->injector->setCustomer($customer);

        return $customer;
    }

    private function cartOf(Customer $customer, string $letter): void
    {
        $cart = $this->factory->cart($customer);
        $this->factory->cartItem($cart, $this->products[$letter]);
        // The price a line carries is the one the core writes when it takes the line in: here its own pricer.
        $this->getService(CartLinePricer::class)->reprice($cart, Currency::getDefaultCurrency(), $customer);
        $this->injector->setCart($cart);
    }

    private function page(string $url): Crawler
    {
        $this->assertPageRenders($url);

        return $this->client->getCrawler();
    }

    private function cardOf(Crawler $page, string $title): Crawler
    {
        $cards = $this->cardsTitled($page, $title);
        self::assertCount(1, $cards, \sprintf('"%s" has one card.', $title));

        return $cards->first();
    }

    private function cardsTitled(Crawler $page, string $title): Crawler
    {
        return $page->filter('.ProductCard')->reduce(static fn (Crawler $card): bool => str_contains($card->text(''), $title));
    }

    private function normalized(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $text)));
    }
}
