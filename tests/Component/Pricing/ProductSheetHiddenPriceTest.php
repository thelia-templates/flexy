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

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Api\Service\DataAccess\ProductSaleElementsAccessService;
use Thelia\Domain\Pricing\PriceVisibility\PriceDisplayMode;
use Thelia\Model\Currency;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Integration\Domain\Pricing\PriceVisibility\PriceDisplayFixtures;
use Thelia\Tests\Support\Flexy\CustomerSessionInjector;

/**
 * A price a visitor is not shown is nowhere in the page they are served: not in the text, not in the
 * props of the live component that redraws the sheet (it is in the source of the page), not in the
 * label of a promotion.
 *
 * The product is on promotion, at amounts nothing else of a page contains (59.37 and 47.13 untaxed): the
 * sheet is first read in a shop that shows every price, which says what the page would leak, then by the
 * visitor of a shop that shows its prices to customers only.
 */
final class ProductSheetHiddenPriceTest extends WebIntegrationTestCase
{
    use PriceDisplayFixtures;
    private const PRODUCT_URL = 'flexy-hidden-price-product.html';

    private FixtureFactory $factory;

    private CustomerSessionInjector $injector;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Not createFixtureFactory(): it leaves a request on the stack, in front of the ones the client sends.
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->injector = new CustomerSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $this->product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), Currency::getDefaultCurrency(), ['ref' => 'HIDDEN-1', 'basePrice' => 59.37, 'baseQuantity' => 50]);
        $pse = $this->product->getDefaultSaleElements();
        $pse->getProductPrices()->getFirst()->setPromoPrice('47.130000')->save($this->getPropelConnection());
        $pse->setPromo(1)->save($this->getPropelConnection());
        $this->product->setLocale('en_US')->setTitle('Hidden price product')->save($this->getPropelConnection());
        $this->product->setRewrittenUrl('en_US', self::PRODUCT_URL);
        $this->forgetPriceDisplayMemos();
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);

        parent::tearDown();
    }

    public function testTheSheetOfAVisitorOfAShopThatShowsItsPricesToCustomersContainsNoAmountOfTheProduct(): void
    {
        $needles = $this->amountsOfTheProduct();
        $leaking = $this->sheetHtml();

        foreach ($needles as $needle) {
            self::assertStringContainsString($needle, $leaking, 'The shop that shows every price prints '.$needle.': the search can see it.');
        }

        $this->shopShows(PriceDisplayMode::LoggedIn);
        $hidden = $this->sheetHtml();

        foreach ($needles as $needle) {
            self::assertStringNotContainsString($needle, $hidden, 'The amount '.$needle.' is readable in the page of a visitor: '.$this->around($hidden, $needle));
        }
    }

    public function testTheSheetOfAVisitorHasNoPromotionLabel(): void
    {
        $label = $this->promotionLabelIn($this->sheetHtml());
        self::assertNotNull($label, 'The shop that shows every price tags the promotion: the search can see it.');

        $this->shopShows(PriceDisplayMode::LoggedIn);

        self::assertNull($this->promotionLabelIn($this->sheetHtml()));
    }

    public function testTheSheetOfAProductOnRequestContainsNoAmountForAVisitorNorForACustomer(): void
    {
        $needles = $this->amountsOfTheProduct();
        $this->productShows($this->product, PriceDisplayMode::OnRequest);

        foreach ([false, true] as $signedIn) {
            if ($signedIn) {
                $this->injector->setCustomer($this->factory->customer($this->factory->customerTitle()));
            }

            $html = $this->sheetHtml();

            foreach ($needles as $needle) {
                self::assertStringNotContainsString($needle, $html, ($signedIn ? 'customer' : 'visitor').': '.$needle.' '.$this->around($html, $needle));
            }

            self::assertNull($this->promotionLabelIn($html));
        }
    }

    public function testTheRequestForAPriceOnTheSheetQuotesTheReferenceOfTheProductAndOfTheCombination(): void
    {
        $this->productShows($this->product, PriceDisplayMode::OnRequest);

        $html = html_entity_decode($this->sheetHtml());

        self::assertStringContainsString('product_ref=HIDDEN-1', $html, 'The reference of the product travels from the sheet to the contact page.');
        self::assertStringContainsString('pse_ref=', $html);
    }

    public function testASignedInCustomerOfAShopThatShowsItsPricesToCustomersIsNeverAskedToSignIn(): void
    {
        $this->shopShows(PriceDisplayMode::LoggedIn);
        $this->injector->setCustomer($this->factory->customer($this->factory->customerTitle()));

        self::assertStringNotContainsString('Log in to see the prices', $this->sheetHtml());
    }

    public function testACustomerStillReadsThePriceAndThePromotionOfTheSheet(): void
    {
        $needles = $this->amountsOfTheProduct();
        $this->shopShows(PriceDisplayMode::LoggedIn);
        $this->injector->setCustomer($this->factory->customer($this->factory->customerTitle()));

        $html = $this->sheetHtml();

        foreach ($needles as $needle) {
            self::assertStringContainsString($needle, $html);
        }

        self::assertNotNull($this->promotionLabelIn($html));
    }

    /**
     * The schema.org offer of the product is written by the SEOne module (hook `layout.head.top`), which
     * asks the core who is shown the price: the Product node of a visitor of a shop that shows its prices
     * to customers carries no amount, and no offer.
     */
    public function testTheStructuredDataOfAVisitorCarriesNoAmountOfTheProduct(): void
    {
        $needles = $this->amountsOfTheProduct();
        $this->shopShows(PriceDisplayMode::LoggedIn);
        $page = $this->decodedPage();
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $page, $blocks);
        $structuredData = implode("\n", $blocks[1]);
        $leaks = array_values(array_filter($needles, static fn (string $amount): bool => str_contains($structuredData, $amount)));

        self::assertStringContainsString('"@type":"Product"', $structuredData, 'The structured data of the product is on the page.');
        self::assertStringNotContainsString('"offers"', $structuredData);
        self::assertSame([], $leaks);
    }

    /**
     * What the page of a shop that shows every price prints: the untaxed and taxed amounts, as
     * numbers (the props of the live component) and as text.
     *
     * @return list<string>
     */
    private function amountsOfTheProduct(): array
    {
        $offered = json_decode((string) $this->getService(ProductSaleElementsAccessService::class)->psesByProduct($this->product->getId()), true, flags: \JSON_THROW_ON_ERROR);
        $needles = ['59.37', '47.13'];

        foreach (['price', 'promoPrice'] as $taxed) {
            $needles[] = number_format((float) $offered[0][$taxed], 2, '.', '');
        }

        self::assertNotContains('0.00', $needles, 'The amounts are read while the prices are shown.');

        return array_values(array_unique($needles));
    }

    /**
     * The page as the visitor reads it, without the structured data the SEOne module writes in the
     * head: that block has a test of its own below, which names the module that owns it.
     */
    private function sheetHtml(): string
    {
        return $this->withoutStructuredData($this->decodedPage());
    }

    private function decodedPage(): string
    {
        $this->assertPageRenders('/'.self::PRODUCT_URL);

        return html_entity_decode((string) $this->client->getResponse()->getContent(), \ENT_QUOTES | \ENT_HTML5);
    }

    private function withoutStructuredData(string $html): string
    {
        return (string) preg_replace('#<script type="application/ld\+json">.*?</script>#s', '', $html);
    }

    private function around(string $html, string $needle): string
    {
        $position = strpos($html, $needle);

        return false === $position ? '' : '...'.substr($html, max(0, $position - 160), 320).'...';
    }

    /**
     * The percentage tag the gallery shows on a promotion, null when there is none.
     */
    private function promotionLabelIn(string $html): ?string
    {
        return 1 === preg_match('/>\s*[-−]?\s?\d+\s?%\s*</u', $html, $match) ? trim($match[0], "<> \n\r\t") : null;
    }
}
