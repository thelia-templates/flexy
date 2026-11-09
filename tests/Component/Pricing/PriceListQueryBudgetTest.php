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

use PHPUnit\Framework\Attributes\DataProvider;
use Propel\Runtime\Propel;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Pricing\PriceVisibility\PriceDisplayMode;
use Thelia\Model\Category;
use Thelia\Model\Currency;
use Thelia\Model\Customer;
use Thelia\Model\Lang;
use Thelia\Model\Product;
use Thelia\Model\ProductImage;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;
use Thelia\Test\Trait\RecordsSqlQueries;
use Thelia\Tests\Integration\Domain\Pricing\PriceList\PriceListFixtures;
use Thelia\Tests\Integration\Domain\Pricing\PriceVisibility\PriceDisplayFixtures;

/**
 * Telling a price costs nothing per card, whatever the shop decides about it: a strip of eight
 * cards costs what a strip of four does, in a shop with a price list for the group of the customer
 * and in a shop that shows its prices to customers only (a visitor reads the invitation to sign in
 * on every card). The same holds for a listing. The figure of a shop without any of it is the one
 * PriceDisplayQueryBudgetTest keeps.
 */
final class PriceListQueryBudgetTest extends IntegrationTestCase
{
    use PriceDisplayFixtures;
    use PriceListFixtures;
    use RecordsSqlQueries;

    private FixtureFactory $factory;

    private ?\Thelia\Model\PriceList $list = null;

    /**
     * @return iterable<string, array{string}>
     */
    public static function shops(): iterable
    {
        yield 'a price list for the group of the customer' => ['price list'];
        yield 'prices shown to customers only, a visitor browsing' => ['logged in'];
        yield 'prices on request on every product' => ['on request'];
    }

    #[DataProvider('shops')]
    public function testAStripOfCardsCostsTheSameWhateverItsLength(string $shop): void
    {
        $products = $this->shop($shop, 13);
        $warmUp = array_pop($products);

        Propel::enableInstancePooling();

        try {
            $this->queriesToRenderStripOf([$warmUp]);

            $four = $this->queriesToRenderStripOf(\array_slice($products, 0, 4));
            $eight = $this->queriesToRenderStripOf(\array_slice($products, 4, 8));
        } finally {
            Propel::disableInstancePooling();
        }

        self::assertSame($four, $eight, \sprintf('Four cards cost %d queries, eight cost %d: a card must not query on its own.', $four, $eight));
    }

    #[DataProvider('shops')]
    public function testAListingCostsTheSameWhateverItsLength(string $shop): void
    {
        $short = $this->factory()->category();
        $long = $this->factory()->category();
        $warmUp = $this->factory()->category();
        $this->shop($shop, 4, $short);
        $this->shop($shop, 8, $long);
        $this->shop($shop, 1, $warmUp);

        Propel::enableInstancePooling();

        try {
            $this->queriesToRenderListingOf($warmUp->getId());

            $four = $this->queriesToRenderListingOf($short->getId());
            $eight = $this->queriesToRenderListingOf($long->getId());
        } finally {
            Propel::disableInstancePooling();
        }

        self::assertSame($four, $eight, \sprintf('A listing of 4 cards costs %d queries, one of 8 costs %d.', $four, $eight));
    }

    private function factory(): FixtureFactory
    {
        return $this->factory ??= $this->createFixtureFactory();
    }

    /**
     * Products in the shop of the scenario, seen by the buyer of the scenario.
     *
     * @return list<Product>
     */
    private function shop(string $shop, int $count, ?Category $category = null): array
    {
        static::getContainer()->get('twig')->addGlobal('lang_code', 'en');

        $products = $this->products($count, $category);

        if ('price list' === $shop) {
            // One list, one group and one customer for the whole test: every product the test creates is priced by it.
            if (null === $this->list) {
                $group = $this->factory()->customerGroup(null, 0.0, false);
                $this->list = $this->priceList();
                $this->assignToGroup($this->list, $group);
                $this->signIn($this->memberOf($group));
            }

            foreach ($products as $product) {
                $this->priceListItem($this->list, $product->getDefaultSaleElements(), '5.000000');
                $this->priceListItem($this->list, $product->getDefaultSaleElements(), '4.000000', 10);
            }
        } elseif ('logged in' === $shop) {
            $this->shopShows(PriceDisplayMode::LoggedIn);
        } else {
            foreach ($products as $product) {
                $product->setPriceDisplayMode(PriceDisplayMode::OnRequest->value)->save($this->getPropelConnection());
            }
        }

        $this->forgetPriceDisplayMemos();

        return $products;
    }

    private function queriesToRenderListingOf(int $categoryId): int
    {
        $twig = static::getContainer()->get('twig');
        $listing = $twig->createTemplate('<twig:Layouts:ProductListing:Base :categoryId="'.$categoryId.'" />');

        return \count($this->recordSqlQueries(static function () use ($listing): void {
            $listing->render();
        }));
    }

    /**
     * @param list<Product> $products
     */
    private function queriesToRenderStripOf(array $products): int
    {
        $renderer = static::getContainer()->get('ux.twig_component.component_renderer');
        \assert($renderer instanceof ComponentRendererInterface);
        $productIds = array_map(static fn (Product $product): int => $product->getId(), $products);

        return \count($this->recordSqlQueries(static function () use ($renderer, $productIds): void {
            $renderer->createAndRender('Layouts:CrossSelling:Base', ['productIds' => $productIds]);
        }));
    }

    private function signIn(Customer $customer): void
    {
        $session = static::getContainer()->get('request_stack')->getMainRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);
        $session->setCustomerUser($customer);
    }

    /**
     * @return list<Product>
     */
    private function products(int $count, ?Category $category): array
    {
        $factory = $this->factory();
        $currency = Currency::getDefaultCurrency();
        $category ??= $factory->category();
        $taxRule = $factory->taxRule();
        $locale = Lang::getDefaultLanguage()->getLocale();
        $products = [];

        for ($n = 1; $n <= $count; ++$n) {
            $product = $factory->product($category, $taxRule, $currency, ['title' => 'Budget list product '.$n, 'locale' => $locale, 'basePrice' => 10.0 * $n, 'baseQuantity' => 10]);

            // The images of a strip are read ahead in one query: a card without any would look its placeholder up on its own.
            (new ProductImage())
                ->setProductId($product->getId())
                ->setVisible(1)
                ->setPosition(1)
                ->setLocale($locale)
                ->setFile(\sprintf('budget-list-%d.jpg', $product->getId()))
                ->save($this->getPropelConnection());

            $products[] = $product;
        }

        return $products;
    }
}
