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

use FlexyBundle\DTO\DisplayedPrice;
use FlexyBundle\DTO\DisplayedPriceTier;
use FlexyBundle\Service\TaxDisplay;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Test\IntegrationTestCase;

/**
 * What a buyer is told about a price, whichever page tells it: the price (and the tiers of its price
 * list), an invitation to sign in when the shop shows prices to customers only, a request for a price
 * when it shows them to nobody, and a neutral sentence for an item an exclusive price list does not
 * sell to them. The component only reads what the core published: the mode, whether the item may be
 * bought, and the price when the caller is shown it.
 */
final class PriceDisplayModeBlockRenderTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        static::getContainer()->get('twig')->addGlobal('lang_code', 'en');
    }

    public function testThePriceIsPrintedWithItsMention(): void
    {
        $block = $this->block(['price' => $this->price(80.0)]);

        self::assertSame('price', $block->filter('.PriceBlock')->attr('data-price-state'));
        self::assertStringContainsString('80.00', $block->text());
        self::assertSame('untaxed', $block->filter('.TaxMention')->attr('data-tax-display'));
    }

    public function testTheCardVariantKeepsTheClassesOfTheCardAndStrikesTheCatalogPrice(): void
    {
        $block = $this->block(['variant' => 'card', 'price' => $this->price(80.0), 'originalPrice' => $this->price(100.0)]);

        self::assertStringContainsString('80.00', $block->filter('.ProductCard-price')->text());
        self::assertStringContainsString('100.00', $block->filter('.ProductCard-originalPrice')->text());
    }

    public function testThePriceOfACardMayBreakOntoTwoLinesSoTheMentionNeverLeavesANarrowCard(): void
    {
        // The mention is nowrap and tied to the amount by a no-break space: on a card 130 px wide
        // (a 320 px screen, two to a row) the pair only fits when the line is allowed to break.
        $block = $this->block(['variant' => 'card', 'price' => $this->price(80.0)]);

        self::assertContains('flex-wrap', explode(' ', (string) $block->filter('.ProductCard-price')->attr('class')));
        self::assertSame('untaxed', $block->filter('.ProductCard-price .TaxMention')->attr('data-tax-display'));
    }

    public function testAVisitorOfAShopThatShowsItsPricesToCustomersIsInvitedToSignInAndComesBackToThePage(): void
    {
        $block = $this->block(['mode' => 'logged_in', 'purchasable' => false, 'returnUrl' => '/a-product.html?pse=3']);

        self::assertSame('hidden', $block->filter('.PriceBlock')->attr('data-price-state'));
        self::assertStringContainsString('Log in to see the prices', $block->text());
        $href = (string) $block->filter('[data-testid="price-login"]')->attr('href');
        self::assertStringStartsWith('/customer/login?', $href);
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);
        self::assertSame('/a-product.html?pse=3', $query['redirect']);
        self::assertDoesNotMatchRegularExpression('/\d+\.\d{2}/', $block->text(), 'No price is printed.');
    }

    public function testTheWayBackIsThePageBeingServed(): void
    {
        $this->serving(Request::create('https://localhost/a-product.html?pse=3'));

        $block = $this->block(['mode' => 'logged_in', 'purchasable' => false]);

        self::assertSame('/a-product.html?pse=3', $this->redirectOf($block));
    }

    public function testTheWayBackOfABlockRedrawnByALiveComponentIsThePageThatAskedForIt(): void
    {
        $request = Request::create('https://localhost/_components/ProductDetails/updateCurrentCombination');
        $request->headers->set('referer', 'https://localhost/a-product.html?pse=3');
        $this->serving($request);

        $block = $this->block(['mode' => 'logged_in', 'purchasable' => false]);

        self::assertSame('/a-product.html?pse=3', $this->redirectOf($block));
    }

    public function testTheWayBackNeverLeadsToAnotherSite(): void
    {
        $request = Request::create('https://localhost/_components/ProductDetails/updateCurrentCombination');
        $request->headers->set('referer', 'https://elsewhere.example/phishing');
        $this->serving($request);

        $block = $this->block(['mode' => 'logged_in', 'purchasable' => false]);

        self::assertSame('/', $this->redirectOf($block));
    }

    public function testAPriceOnRequestOffersTheRequestToEverybodyAndCarriesTheReferencesAndNoAmount(): void
    {
        // Even handed a price: the mode says nobody is shown it, so nothing of it may reach the page or the link.
        $block = $this->block([
            'mode' => 'on_request',
            'purchasable' => false,
            'price' => $this->price(123.45),
            'reference' => 'SKU-1',
            'productReference' => 'PRODUCT-1',
        ]);

        self::assertSame('on_request', $block->filter('.PriceBlock')->attr('data-price-state'));
        self::assertStringContainsString('Price on request', $block->text());
        self::assertStringContainsString('Request a price', $block->text());
        $href = (string) $block->filter('[data-testid="price-request"]')->attr('href');
        self::assertSame('/contact?product_ref=PRODUCT-1&pse_ref=SKU-1', $href);
        self::assertStringNotContainsString('123', $block->html());
    }

    public function testTheRequestWithoutReferenceStillLeadsToTheContactPage(): void
    {
        $block = $this->block(['mode' => 'on_request', 'purchasable' => false]);

        self::assertSame('/contact', $block->filter('[data-testid="price-request"]')->attr('href'));
    }

    public function testAnItemAnExclusivePriceListDoesNotSellKeepsItsPublicPriceWithTheMentionAndNoButton(): void
    {
        foreach (['sheet', 'card'] as $variant) {
            $block = $this->block([
                'price' => $this->price(100.0),
                'originalPrice' => $this->price(120.0),
                'purchasable' => false,
                'variant' => $variant,
                'tiers' => [$this->tier(1, 10.0), $this->tier(10, 9.0)],
            ]);

            self::assertSame('unavailable', $block->filter('.PriceBlock')->attr('data-price-state'), $variant);
            self::assertStringContainsString('100.00', $block->text(), $variant);
            self::assertStringContainsString('Not available for purchase', $block->filter('[data-testid="price-unavailable"]')->text(), $variant);
            self::assertStringNotContainsString('120.00', $block->text(), $variant.': nothing is struck through on what cannot be bought');
            self::assertCount(0, $block->filter('[data-testid="price-tiers"]'), $variant.': no table of tiers on what cannot be bought');
            self::assertCount(0, $block->filter('a, button'), $variant);
        }
    }

    public function testNothingIsPrintedWhenTheCoreHasNothingToSay(): void
    {
        self::assertSame('', trim($this->html(['mode' => 'visible'])));
    }

    public function testAnUnknownModeReadsAsVisible(): void
    {
        $block = $this->block(['mode' => 'something-new', 'price' => $this->price(80.0)]);

        self::assertSame('price', $block->filter('.PriceBlock')->attr('data-price-state'));
    }

    public function testTheTiersAreListedWhenThePriceChangesWithTheQuantity(): void
    {
        $block = $this->block(['price' => $this->price(10.0), 'tiers' => [$this->tier(1, 10.0), $this->tier(10, 9.0)]]);

        $rows = $block->filter('[data-testid="price-tiers"] li')->each(static fn (Crawler $row): string => trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $row->text()))));

        self::assertSame(['From 1: €10.00 excl. tax', 'From 10: €9.00 excl. tax'], $rows);
    }

    public function testASingleTierFromOneUnitIsThePriceItselfAndIsNotListed(): void
    {
        $block = $this->block(['price' => $this->price(10.0), 'tiers' => [$this->tier(1, 10.0)]]);

        self::assertCount(0, $block->filter('[data-testid="price-tiers"]'));
    }

    public function testASingleTierFromSeveralUnitsIsListedBecauseOneUnitCostsAnotherPrice(): void
    {
        $block = $this->block(['price' => $this->price(12.0), 'tiers' => [$this->tier(5, 9.0)]]);

        self::assertCount(1, $block->filter('[data-testid="price-tiers"] li'));
    }

    public function testASignedInCustomerWhoseItemHasNoPriceInTheCurrencyIsNotAskedToSignIn(): void
    {
        // The core refuses the purchase to the visitor it hides the price from, and to nobody else.
        $customer = $this->block(['mode' => 'logged_in', 'purchasable' => true, 'price' => null]);
        self::assertCount(0, $customer->filter('[data-testid="price-login"]'));
        self::assertCount(0, $customer->filter('.PriceBlock'), 'Nothing to say: no invitation to a customer who is signed in.');

        $visitor = $this->block(['mode' => 'logged_in', 'purchasable' => false, 'price' => null]);
        self::assertSame('hidden', $visitor->filter('.PriceBlock')->attr('data-price-state'));
    }

    public function testTheRequestForAPriceLeadsToTheContactPageOfTheFolderTheShopLivesIn(): void
    {
        $context = static::getContainer()->get('router')->getContext();
        $previous = $context->getBaseUrl();
        $context->setBaseUrl('/shop');

        try {
            $block = $this->block(['mode' => 'on_request', 'purchasable' => false, 'reference' => 'SKU-1', 'productReference' => 'PRODUCT-1']);
        } finally {
            $context->setBaseUrl($previous);
        }

        self::assertSame('/shop/contact?product_ref=PRODUCT-1&pse_ref=SKU-1', $block->filter('[data-testid="price-request"]')->attr('href'));
    }

    public function testTheTiersHaveATitleThatSaysWhatTheyAre(): void
    {
        $block = $this->block(['price' => $this->price(10.0), 'tiers' => [$this->tier(1, 10.0), $this->tier(10, 9.0)]]);

        self::assertSame('Price by quantity', trim($block->filter('[data-testid="price-tiers-title"]')->text()));
        self::assertCount(1, $block->filter('.PriceBlock-tiersBlock [data-testid="price-tiers-title"] + [data-testid="price-tiers"]'), 'The title sits right above the list it names.');
    }

    public function testInsideALinkTheWordingStaysAndTheButtonsGo(): void
    {
        $login = $this->block(['mode' => 'logged_in', 'purchasable' => false, 'actions' => false]);
        self::assertStringContainsString('Log in to see the prices', $login->text());
        self::assertCount(0, $login->filter('a'));

        $request = $this->block(['mode' => 'on_request', 'purchasable' => false, 'actions' => false]);
        self::assertStringContainsString('Price on request', $request->text());
        self::assertCount(0, $request->filter('a'));
    }

    private function price(float $amount): DisplayedPrice
    {
        return new DisplayedPrice($amount, TaxDisplay::Untaxed);
    }

    private function tier(int $minQuantity, float $amount): DisplayedPriceTier
    {
        return new DisplayedPriceTier($minQuantity, $this->price($amount));
    }

    private function serving(Request $request): void
    {
        $requestStack = static::getContainer()->get(RequestStack::class);

        while (null !== $requestStack->pop()) {
        }

        $requestStack->push($request);
    }

    private function redirectOf(Crawler $block): string
    {
        $href = (string) $block->filter('[data-testid="price-login"]')->attr('href');
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);

        return (string) $query['redirect'];
    }

    /**
     * @param array<string, mixed> $props
     */
    private function block(array $props): Crawler
    {
        return new Crawler('<body>'.$this->html($props).'</body>');
    }

    /**
     * @param array<string, mixed> $props
     */
    private function html(array $props): string
    {
        $renderer = static::getContainer()->get('ux.twig_component.component_renderer');
        \assert($renderer instanceof ComponentRendererInterface);

        return $renderer->createAndRender('Molecules:PriceBlock:Base', $props);
    }
}
