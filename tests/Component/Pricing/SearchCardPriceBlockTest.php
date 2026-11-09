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

use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The row of the search suggestions says about a price what the card and the sheet say, through the
 * same block: a price the buyer is not shown, or may not buy, is not printed, and the row is a link
 * so it carries no button.
 */
final class SearchCardPriceBlockTest extends IntegrationTestCase
{
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $fixtures = new FixtureFactory($this->getPropelConnection());
        $this->productId = $fixtures->product($fixtures->category(), $fixtures->taxRule(), $fixtures->currency())->getId();

        $requestStack = self::getContainer()->get('request_stack');
        \assert($requestStack instanceof RequestStack);
        while (null !== $requestStack->pop()) {
        }
        $request = Request::create('https://localhost/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');
    }

    #[IgnoreDeprecations]
    public function testASaleElementThatMayBeBoughtShowsItsPrice(): void
    {
        $row = $this->row(['priceDisplayMode' => 'visible', 'purchasable' => true]);

        self::assertMatchesRegularExpression('/(25|30)\.00/', $row->filter('.ProductCard-price')->text());
        self::assertSame('price', $row->filter('.PriceBlock')->attr('data-price-state'));
    }

    #[IgnoreDeprecations]
    public function testASaleElementAnExclusiveListClosesShowsItsPriceWithTheMention(): void
    {
        $row = $this->row(['priceDisplayMode' => 'visible', 'purchasable' => false]);

        self::assertSame('unavailable', $row->filter('.PriceBlock')->attr('data-price-state'));
        self::assertMatchesRegularExpression('/(25|30)\.00/', $row->filter('.ProductCard-price')->text());
        self::assertStringContainsString('Not available for purchase', $row->filter('[data-testid="price-unavailable"]')->text());
    }

    #[IgnoreDeprecations]
    public function testAPriceTheVisitorIsNotShownIsReplacedByTheWordingWithoutAButton(): void
    {
        $hidden = $this->row(['priceDisplayMode' => 'logged_in', 'purchasable' => false], withPrice: false);
        self::assertStringContainsString('Log in to see the prices', $hidden->text());

        $onRequest = $this->row(['priceDisplayMode' => 'on_request', 'purchasable' => false], withPrice: false);
        self::assertStringContainsString('Price on request', $onRequest->text());

        foreach ([$hidden, $onRequest] as $row) {
            self::assertCount(1, $row->filter('a'), 'The row is the only link: no button nests in it.');
            self::assertDoesNotMatchRegularExpression('/\d+\.\d{2}/', $row->text());
        }
    }

    /**
     * @param array<string, mixed> $pseExtra
     */
    private function row(array $pseExtra, bool $withPrice = true): Crawler
    {
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');
        \assert($renderer instanceof ComponentRendererInterface);

        $html = $renderer->createAndRender('Organisms:ProductCard:Search', ['product' => [
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
                'ref' => 'PSE-1',
                'isDefault' => true,
                'promo' => false,
                'newness' => false,
                'productPrices' => $withPrice ? [['price' => 25.0, 'promoPrice' => 25.0, 'taxedPrice' => 30.0, 'taxedPromoPrice' => 30.0, 'currency' => 'EUR']] : [],
                'attributeCombinations' => [],
            ] + $pseExtra],
        ]]);

        return new Crawler('<body>'.$html.'</body>');
    }
}
