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

use FlexyBundle\Service\PriceDisplay;
use PHPUnit\Framework\Attributes\DataProvider;
use Propel\Runtime\Propel;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Category;
use Thelia\Model\Lang;
use Thelia\Model\Product;
use Thelia\Model\ProductImage;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;
use Thelia\Test\Trait\RecordsSqlQueries;

/**
 * Deciding the display of a price costs nothing per card: the group of the visitor is read once,
 * and the cost of a strip of cards does not grow with it, with or without the taxes (the prices of
 * both forms come with the product, the theme taxes nothing). Runs on an installed shop
 * (bin/test-prepare).
 */
final class PriceDisplayQueryBudgetTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    public function testTheDisplayOfTheVisitorIsReadOnlyOnce(): void
    {
        $this->signIn(untaxed: true);
        $priceDisplay = $this->getService(PriceDisplay::class);

        Propel::enableInstancePooling();

        try {
            $first = \count($this->recordSqlQueries(static function () use ($priceDisplay): void {
                $priceDisplay->forVisitor();
            }));
            $fiftyMore = \count($this->recordSqlQueries(static function () use ($priceDisplay): void {
                for ($card = 0; $card < 50; ++$card) {
                    $priceDisplay->forVisitor();
                }
            }));
        } finally {
            Propel::disableInstancePooling();
        }

        self::assertGreaterThan(0, $first, 'The first answer reads the group of the visitor.');
        self::assertSame(0, $fiftyMore, 'Asking again reads nothing: the answer is not paid for once per card.');
    }

    /**
     * The taxed price of a card is the one the core publishes with the product: the theme taxes
     * nothing itself, so a card costs no query of its own in either display, and a strip of eight
     * costs what a strip of four does.
     */
    #[DataProvider('displays')]
    public function testAStripOfCardsCostsTheSameWhateverItsLength(bool $untaxed): void
    {
        $this->signIn(untaxed: $untaxed);
        $products = $this->products(13);
        $warmUp = array_pop($products);

        // The test case turns the pool off so that every read reaches the database; the shop runs with it on.
        Propel::enableInstancePooling();

        try {
            // Warms what is read once per request (the config, the lang, the currency, the group).
            $this->queriesToRenderStripOf([$warmUp]);

            $four = $this->queriesToRenderStripOf(\array_slice($products, 0, 4));
            $eight = $this->queriesToRenderStripOf(\array_slice($products, 4, 8));
        } finally {
            Propel::disableInstancePooling();
        }

        self::assertSame($four, $eight, \sprintf('Four cards cost %d queries, eight cost %d: a card must not query on its own.', $four, $eight));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function displays(): iterable
    {
        yield 'prices with their taxes' => [false];
        yield 'prices without their taxes' => [true];
    }

    public function testAListingOfCardsCostsTheSameWhateverItsLengthWithTheTaxes(): void
    {
        $this->signIn(untaxed: false);
        $short = $this->factory()->category();
        $long = $this->factory()->category();
        $warmUp = $this->factory()->category();
        $this->products(4, $short);
        $this->products(8, $long);
        $this->products(1, $warmUp);

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

    private function queriesToRenderListingOf(int $categoryId): int
    {
        $twig = static::getContainer()->get('twig');
        $listing = $twig->createTemplate('<twig:Layouts:ProductListing:Base :categoryId="'.$categoryId.'" />');

        $statements = $this->recordSqlQueries(static function () use ($listing): void {
            $listing->render();
        });

        return \count($statements);
    }

    private function factory(): FixtureFactory
    {
        return $this->createFixtureFactory();
    }

    /**
     * @param list<Product> $products
     */
    private function queriesToRenderStripOf(array $products): int
    {
        $renderer = static::getContainer()->get('ux.twig_component.component_renderer');
        \assert($renderer instanceof ComponentRendererInterface);
        $productIds = array_map(static fn (Product $product): int => $product->getId(), $products);

        $statements = $this->recordSqlQueries(static function () use ($renderer, $productIds): void {
            $renderer->createAndRender('Layouts:CrossSelling:Base', ['productIds' => $productIds]);
        });

        return \count($statements);
    }

    private function signIn(bool $untaxed): void
    {
        $factory = $this->createFixtureFactory();
        $customer = $factory->customer($factory->customerTitle(), $untaxed ? ['customerGroup' => $factory->customerGroup(null, 0.0, false)] : []);

        $session = static::getContainer()->get('request_stack')->getMainRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);
        $session->setCustomerUser($customer);

        // The shop remembers for the length of a request whether a group reads its prices otherwise: a
        // group created in the middle of the test is not seen until it forgets.
        static::getContainer()->get('services_resetter')->reset();
    }

    /**
     * @return list<Product>
     */
    private function products(int $count, ?Category $category = null): array
    {
        static::getContainer()->get('twig')->addGlobal('lang_code', 'en');

        $factory = $this->createFixtureFactory();
        $currency = $factory->currency();
        $category ??= $factory->category();
        $taxRule = $factory->taxRule();
        $locale = Lang::getDefaultLanguage()->getLocale();
        $products = [];

        for ($n = 1; $n <= $count; ++$n) {
            $product = $factory->product($category, $taxRule, $currency, ['title' => 'Budget product '.$n, 'locale' => $locale, 'basePrice' => 10.0 * $n, 'baseQuantity' => 10]);

            // The images of a strip are read ahead in one query, which is what keeps the cost of a card
            // out of the figures below: a card without any would look its placeholder up on its own.
            (new ProductImage())
                ->setProductId($product->getId())
                ->setVisible(1)
                ->setPosition(1)
                ->setLocale($locale)
                ->setFile(\sprintf('budget-%d.jpg', $product->getId()))
                ->save($this->getPropelConnection());

            $products[] = $product;
        }

        return $products;
    }
}
