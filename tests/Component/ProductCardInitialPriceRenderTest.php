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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * A card strikes the catalog price through, and tags the discount, only when the front API
 * lets it: a sale element priced by a rule or an operation that asks to hide the catalog price
 * shows the price charged alone.
 */
final class ProductCardInitialPriceRenderTest extends IntegrationTestCase
{
    /** A product the tax engine can find: a card prices its taxes off the product itself. */
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $fixtures = new FixtureFactory($this->getPropelConnection());
        $this->productId = $fixtures->product($fixtures->category(), $fixtures->taxRule(), $fixtures->currency())->getId();

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        // The base class leaves a bare request on the stack, which would be the main one: this test is about the request pushed below.
        while (null !== $requestStack->pop()) {
        }
        $request = Request::create('https://localhost/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');
    }

    /**
     * @param array<string, mixed> $pseExtra
     */
    private function renderCard(string $component, array $pseExtra): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender($component, ['product' => [
            'id' => $this->productId,
            'ref' => 'REF-'.$this->productId,
            'visible' => true,
            'position' => 1,
            'virtual' => false,
            'publicUrl' => '/organic-cotton-t-shirt',
            'i18ns' => ['title' => 'Organic cotton t-shirt'],
            'ProductColor' => ['colors' => []],
            'productCategories' => [],
            'productSaleElements' => [[
                'id' => 1,
                'isDefault' => true,
                'promo' => true,
                'newness' => false,
                'productPrices' => [['price' => 25.0, 'promoPrice' => 20.0, 'taxedPrice' => 30.0, 'taxedPromoPrice' => 24.0, 'currency' => 'EUR']],
                'attributeCombinations' => [],
            ] + $pseExtra],
        ]]);
    }

    #[IgnoreDeprecations]
    public function testACardWhoseSaleElementHidesTheCatalogPriceShowsNeitherTheStrikeNorTheBadge(): void
    {
        foreach (['Organisms:ProductCard:Base', 'Organisms:ProductCard:Search'] as $component) {
            $html = $this->renderCard($component, ['displayInitialPrice' => false]);

            self::assertStringContainsString('ProductCard-price', $html, $component);
            self::assertStringNotContainsString('ProductCard-originalPrice', $html, $component);
            self::assertStringNotContainsString('Tag--error', $html, $component);
        }
    }

    #[IgnoreDeprecations]
    public function testACardKeepsTheStrikeAndTheBadgeOfARealPromotion(): void
    {
        foreach (['Organisms:ProductCard:Base', 'Organisms:ProductCard:Search'] as $component) {
            foreach ([['displayInitialPrice' => true], ['displayInitialPrice' => null], []] as $extra) {
                $html = $this->renderCard($component, $extra);

                self::assertStringContainsString('ProductCard-originalPrice', $html, $component);
            }
        }

        self::assertStringContainsString('Tag--error', $this->renderCard('Organisms:ProductCard:Base', []));
    }
}
