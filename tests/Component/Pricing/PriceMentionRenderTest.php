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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Test\IntegrationTestCase;

/**
 * The components that print a price carry its mention: a unit price of the quick order table, or
 * the mention component alone.
 */
final class PriceMentionRenderTest extends IntegrationTestCase
{
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();

        $requestStack = static::getContainer()->get('request_stack');
        // The base class leaves a bare request on the stack: this test reads the one pushed below.
        while (null !== $requestStack->pop()) {
        }

        $request = Request::create('https://localhost/account/quick-order');
        $this->session = new Session(new MockArraySessionStorage());
        $request->setSession($this->session);
        $requestStack->push($request);

        static::getContainer()->get('twig')->addGlobal('lang_code', 'en');
    }

    public function testTheMentionComponentSaysWhichOfTheTwoAmountsItFollows(): void
    {
        $untaxed = $this->render('<twig:Atoms:TaxMention:Base :display="enum(\'FlexyBundle\\\\Service\\\\TaxDisplay\').Untaxed" />');
        $taxed = $this->render('<twig:Atoms:TaxMention:Base :display="enum(\'FlexyBundle\\\\Service\\\\TaxDisplay\').Taxed" />');

        self::assertSame('excl. tax', trim($untaxed->filter('.TaxMention')->text()));
        self::assertSame('untaxed', $untaxed->filter('.TaxMention')->attr('data-tax-display'));
        self::assertSame('incl. tax', trim($taxed->filter('.TaxMention')->text()));
        self::assertSame('taxed', $taxed->filter('.TaxMention')->attr('data-tax-display'));
    }

    public function testTheMentionIsSeparatedFromTheAmountItFollowsInTheTextOfThePrice(): void
    {
        $price = $this->render('<span class="price"><span dir="ltr">€0.00</span><twig:Atoms:TaxMention:Base :display="enum(\'FlexyBundle\\\\Service\\\\TaxDisplay\').Taxed" /></span>')->filter('.price');

        // A no-break space: the text extracted from the page reads "€0.00 incl. tax", and the two cannot be split by a line break.
        self::assertSame("€0.00\u{a0}incl. tax", $price->text());
        self::assertDoesNotMatchRegularExpression('/[0-9]incl/', $price->text());
    }

    public function testTheQuickOrderTableWritesTheUnitPriceOfTheDisplayOfTheVisitor(): void
    {
        $taxedRow = $this->quickOrderUnitPrice();

        self::assertStringContainsString('0.42', $taxedRow->text());
        self::assertSame('taxed', $taxedRow->filter('.TaxMention')->attr('data-tax-display'));

        $factory = $this->createFixtureFactory();
        $this->session->setCustomerUser($factory->customer($factory->customerTitle(), ['customerGroup' => $factory->customerGroup(null, 0.0, false)]));
        // The shop remembers for the length of a request whether a group reads its prices otherwise: the group
        // created above is not seen until it forgets.
        static::getContainer()->get('services_resetter')->reset();

        $untaxedRow = $this->quickOrderUnitPrice();

        self::assertStringContainsString('0.35', $untaxedRow->text());
        self::assertStringNotContainsString('0.42', $untaxedRow->text());
        self::assertSame('untaxed', $untaxedRow->filter('.TaxMention')->attr('data-tax-display'));
    }

    public function testTheMentionIsWrittenInOneWordingEverywhereAPriceIsTold(): void
    {
        $filter = $this->render('<i>{{ \'untaxed\'|tax_mention }}|{{ enum(\'FlexyBundle\\\\Service\\\\TaxDisplay\').Taxed|tax_mention }}|{{ displayed_price(enum(\'FlexyBundle\\\\Service\\\\TaxDisplay\').Untaxed, 1.0, 2.0)|tax_mention }}</i>');

        self::assertSame('excl. tax|incl. tax|excl. tax', $filter->filter('i')->text());
    }

    public function testTheSliderTellsTheMentionToAScreenReaderWithTheSameWording(): void
    {
        $slider = $this->render('<twig:Fields:RangeSlider:Base nameMin="a" nameMax="b" :min="10" :max="100" currency="EUR" locale="en" taxDisplay="untaxed" />');

        self::assertStringContainsString(' excl. tax"', (string) $slider->filter('input[type=range]')->first()->attr('aria-valuetext').'"');
        self::assertSame('excl. tax', trim($slider->filter('.TaxMention')->text()));
    }

    public function testABuyerWhoseVatIsExemptedIsNotToldItsFiguresIncludeTax(): void
    {
        $order = ['customerGroupDisplayTaxedPrice' => true, 'vatExempted' => true, 'totalAmount' => 100.0, 'totalAmountWithoutTaxes' => 100.0, 'totalAmountWithoutTaxesWithShipping' => 100.0, 'orderProducts' => [], 'invoiceOrderAddress' => ['vatNumber' => 'FR123']];
        $withoutExemption = ['vatExempted' => false] + $order;

        $exempted = $this->render('<twig:Organisms:Summary:Order :order="order" />', ['order' => $order]);
        $taxed = $this->render('<twig:Organisms:Summary:Order :order="order" />', ['order' => $withoutExemption]);

        self::assertCount(0, $exempted->filter('.TaxMention'), 'No figure is said to include a tax nobody pays.');
        self::assertStringContainsString('VAT exempted', $exempted->text());
        self::assertGreaterThan(0, $taxed->filter('.TaxMention')->count(), 'The same order without the exemption keeps its mention.');
    }

    private function quickOrderUnitPrice(): Crawler
    {
        return $this->render(
            '<twig:Organisms:QuickOrderTable:Base'
            .' :rows="[{key: \'a\', reference: \'VIS-M6-20\', quantity: 3, productSaleElementsId: 1}]"'
            .' :lines="[{reference: \'vis-m6-20\', quantity: 3, status: \'resolved\', title: \'Screw\', taxedUnitPrice: 0.42, untaxedUnitPrice: 0.35, availableQuantity: null, candidates: []}]" />'
        )->filter('td.QuickOrderTable-price');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context = []): Crawler
    {
        return new Crawler(static::getContainer()->get('twig')->createTemplate($template)->render($context));
    }
}
