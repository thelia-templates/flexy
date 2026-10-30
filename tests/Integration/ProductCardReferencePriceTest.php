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

use FlexyBundle\Components\Organisms\ProductCard\Base as ProductCard;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\ComponentFactory;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Domain\Catalog\Quantity\ReferenceUnitPriceCalculator;
use Thelia\Test\IntegrationTestCase;

/**
 * A card shows the price per litre of a bottle of 75 cl under the price it shows, the
 * promotion price taxes included; a card whose sale element says nothing of its units shows
 * none. The product exists: the card prices it with its tax rule.
 */
final class ProductCardReferencePriceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        if (!class_exists(ReferenceUnitPriceCalculator::class)) {
            self::markTestSkipped('This core has no reference unit prices.');
        }

        parent::setUp();
        static::getContainer()->get('twig')->addGlobal('lang_code', 'en');
    }

    #[IgnoreDeprecations]
    public function testTheCardShowsThePricePerLitreOfThePriceItShows(): void
    {
        $product = $this->product();
        $data = $this->payload($product, ['unitQuantity' => 0.75, 'referenceUnitQuantity' => 1, 'unitCode' => 'l']);

        $shown = $this->card($data)->getDisplayedPromoPrice()?->amount;
        self::assertIsFloat($shown);

        $line = (new Crawler($this->render($data)))->filter('.ProductCard-referencePrice');
        self::assertCount(1, $line);
        self::assertSame('€'.number_format(round($shown / 0.75, 2), 2).' / l', trim($line->text()));
    }

    #[IgnoreDeprecations]
    public function testACardWithoutUnitsShowsNoReferencePrice(): void
    {
        $html = $this->render($this->payload($this->product(), []));

        self::assertStringContainsString('ProductCard-price', $html);
        self::assertStringNotContainsString('ProductCard-referencePrice', $html);
    }

    private function product(): \Thelia\Model\Product
    {
        $factory = $this->createFixtureFactory();

        return $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['basePrice' => 25.0]);
    }

    /**
     * @param array<string, mixed> $units
     *
     * @return array<string, mixed>
     */
    private function payload(\Thelia\Model\Product $product, array $units): array
    {
        return [
            'id' => $product->getId(),
            'ref' => $product->getRef(),
            'visible' => true,
            'position' => 1,
            'virtual' => false,
            'publicUrl' => '/bottle',
            'i18ns' => ['title' => 'Bottle'],
            'ProductColor' => ['colors' => []],
            'productCategories' => [],
            'productSaleElements' => [[
                'id' => 1,
                'isDefault' => true,
                'promo' => true,
                'newness' => false,
                'productPrices' => [['price' => 25.0, 'promoPrice' => 20.0, 'taxedPrice' => 30.0, 'taxedPromoPrice' => 24.0, 'currency' => 'EUR']],
                'attributeCombinations' => [],
            ] + $units],
        ];
    }

    /**
     * @param array<string, mixed> $product
     */
    private function card(array $product): ProductCard
    {
        $factory = static::getContainer()->get('ux.twig_component.component_factory');
        \assert($factory instanceof ComponentFactory);
        $card = $factory->create('Organisms:ProductCard:Base', ['product' => $product])->getComponent();
        \assert($card instanceof ProductCard);

        return $card;
    }

    /**
     * @param array<string, mixed> $product
     */
    private function render(array $product): string
    {
        $renderer = static::getContainer()->get('ux.twig_component.component_renderer');
        \assert($renderer instanceof ComponentRendererInterface);

        return $renderer->createAndRender('Organisms:ProductCard:Base', ['product' => $product]);
    }
}
