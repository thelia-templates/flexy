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

use FlexyBundle\Service\ProductImageResolver;
use Propel\Runtime\Propel;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Model\Lang;
use Thelia\Model\Product;
use Thelia\Model\ProductImage;
use Thelia\Model\ProductImageQuery;
use Thelia\Test\IntegrationTestCase;
use Thelia\Test\Trait\RecordsSqlQueries;

/**
 * A strip of product cards reads what its cards show in a number of queries that does not grow
 * with the strip: the images of every card are read ahead, in one query. Runs on an installed
 * shop (bin/test-prepare).
 */
final class ProductStripQueryBudgetTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    private const IMAGES_PER_PRODUCT = 3;

    public function testTheQueriesOfAStripDoNotGrowWithItsCards(): void
    {
        $products = $this->productsWithImages(13);
        $warmUp = array_pop($products);

        // The test case turns the pool off so that every read reaches the database; the shop runs with it on.
        Propel::enableInstancePooling();

        try {
            // Warms what is read once per request (the config, the lang, the currency).
            $this->queriesToRenderStripOf([$warmUp]);

            $four = $this->queriesToRenderStripOf(\array_slice($products, 0, 4));
            $eight = $this->queriesToRenderStripOf(\array_slice($products, 4, 8));
        } finally {
            Propel::disableInstancePooling();
        }

        self::assertEqualsWithDelta(
            $four,
            $eight,
            1,
            \sprintf('Four cards cost %d queries, eight cost %d: a card must not query on its own.', $four, $eight),
        );
    }

    public function testTheFirstVisibleImageByPositionIsTheOneOfTheCard(): void
    {
        $products = $this->productsWithImages(2);
        $hidden = $products[0]->getProductImages()->getFirst();
        $hidden->setVisible(0)->save($this->getPropelConnection());
        $withoutImage = $this->productsWithImages(1, 0)[0];

        $resolver = $this->getService(ProductImageResolver::class);
        $resolver->preload([$products[0]->getId(), $products[1]->getId(), $withoutImage->getId()]);

        $expectedFirst = static fn (Product $product): int => (int) $product->getProductImages(
            ProductImageQuery::create()->filterByVisible(1)->orderByPosition(),
        )->getFirst()->getId();

        self::assertSame($expectedFirst($products[0]), $resolver->firstImageId($products[0]->getId()));
        self::assertNotSame($hidden->getId(), $resolver->firstImageId($products[0]->getId()));
        self::assertSame($expectedFirst($products[1]), $resolver->firstImageId($products[1]->getId()));
        self::assertNull($resolver->firstImageId($withoutImage->getId()));
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

    /**
     * @return list<Product>
     */
    private function productsWithImages(int $count, int $imagesPerProduct = self::IMAGES_PER_PRODUCT): array
    {
        static::getContainer()->get('twig')->addGlobal('lang_code', 'en');

        $factory = $this->createFixtureFactory();
        $currency = $factory->currency();
        $category = $factory->category();
        $taxRule = $factory->taxRule();
        $locale = Lang::getDefaultLanguage()->getLocale();
        $products = [];

        for ($n = 1; $n <= $count; ++$n) {
            $product = $factory->product($category, $taxRule, $currency, ['title' => 'Strip product '.$n, 'locale' => $locale, 'basePrice' => 10.0 * $n, 'baseQuantity' => 10]);

            for ($position = 1; $position <= $imagesPerProduct; ++$position) {
                (new ProductImage())
                    ->setProductId($product->getId())
                    ->setVisible(1)
                    ->setPosition($position)
                    ->setLocale($locale)
                    ->setFile(\sprintf('strip-%d-%d.jpg', $product->getId(), $position))
                    ->save($this->getPropelConnection());
            }

            $products[] = $product;
        }

        return $products;
    }
}
