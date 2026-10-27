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
use Thelia\Domain\Taxation\TaxEngine\TaxEngine;
use Thelia\Model\Customer;
use Thelia\Model\CustomerGroup;
use Thelia\Model\CustomerGroupQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProductTax;
use Thelia\Model\OrderStatus;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\Flexy\CustomerSessionInjector;

/**
 * What a buyer reads on the pages of the shop follows the display of their customer group: the
 * price with its taxes for the default group, without them for a group shown untaxed prices, and
 * every figure says which with the mention component. A placed order keeps the display it was
 * sold under, whatever group its buyer belongs to later.
 */
final class TaxDisplayPagesTest extends WebIntegrationTestCase
{
    private const CATEGORY_URL = 'flexy-tax-display-category.html';

    private const PRODUCT_URL = 'flexy-tax-display-product.html';

    private FixtureFactory $factory;

    private CustomerSessionInjector $injector;

    private CustomerGroup $untaxedGroup;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Not createFixtureFactory(): it leaves a request on the stack, in front of the ones the client sends.
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->injector = new CustomerSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $this->untaxedGroup = $this->factory->customerGroup('pro-untaxed', 0.0, false);

        $category = $this->factory->category();
        $category->setLocale('en_US')->setTitle('Tax display category')->save($this->getPropelConnection());
        $category->setRewrittenUrl('en_US', self::CATEGORY_URL);

        $this->product = $this->factory->product($category, $this->factory->taxRule(), $this->factory->currency(), ['basePrice' => 100.0, 'baseQuantity' => 50]);
        $this->product->setLocale('en_US')->setTitle('Tax display product')->save($this->getPropelConnection());
        $this->product->setRewrittenUrl('en_US', self::PRODUCT_URL);
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);

        parent::tearDown();
    }

    public function testTheProductPageOfAnUntaxedGroupShowsTheUntaxedPriceWithItsMention(): void
    {
        $this->signedIn($this->untaxedGroup);

        $price = $this->page('/'.self::PRODUCT_URL)->filter('.PseSelector-price');

        self::assertMatchesRegularExpression('/100\.00/', $price->text());
        self::assertSame('untaxed', $price->filter('.TaxMention')->attr('data-tax-display'));
        self::assertSame('excl. tax', trim($price->filter('.TaxMention')->text()));
    }

    public function testTheProductPageOfTheDefaultGroupShowsTheTaxedPriceWithItsMention(): void
    {
        $this->signedIn();

        $price = $this->page('/'.self::PRODUCT_URL)->filter('.PseSelector-price');

        self::assertStringContainsString($this->taxed(), $price->text());
        self::assertSame('taxed', $price->filter('.TaxMention')->attr('data-tax-display'));
    }

    public function testAVisitorWhoIsNotSignedInReadsTheDefaultGroupDisplay(): void
    {
        $price = $this->page('/'.self::PRODUCT_URL)->filter('.PseSelector-price');

        self::assertStringContainsString($this->taxed(), $price->text());
        self::assertSame('taxed', $price->filter('.TaxMention')->attr('data-tax-display'));
    }

    public function testTheListingOfAnUntaxedGroupShowsTheUntaxedPriceOnEachCard(): void
    {
        $this->signedIn($this->untaxedGroup);

        $card = $this->page('/'.self::CATEGORY_URL)->filter('.ProductCard-price')->first();

        self::assertMatchesRegularExpression('/100\.00/', $card->text());
        self::assertSame('untaxed', $card->filter('.TaxMention')->attr('data-tax-display'));
    }

    public function testTheListingOfTheDefaultGroupShowsTheTaxedPriceOnEachCard(): void
    {
        $this->signedIn();

        $card = $this->page('/'.self::CATEGORY_URL)->filter('.ProductCard-price')->first();

        self::assertStringContainsString($this->taxed(), $card->text());
        self::assertSame('taxed', $card->filter('.TaxMention')->attr('data-tax-display'));
    }

    public function testTheCartOfAnUntaxedGroupShowsTheLinesAndTheTotalUntaxed(): void
    {
        $customer = $this->signedIn($this->untaxedGroup);
        $this->cartOf($customer);

        $page = $this->page('/checkout/cart');

        self::assertMatchesRegularExpression('/100\.00/', $page->filter('.CartItem-price')->first()->text());
        self::assertSame('untaxed', $page->filter('.CartItem-price .TaxMention')->first()->attr('data-tax-display'));
        // The total without taxes, the taxes, then what is to pay: all three the core's own figures.
        self::assertMatchesRegularExpression('/100\.00/', $page->filter('[data-summary-total-without-taxes]')->text());
        self::assertStringContainsString(number_format((float) $this->taxed() - 100.0, 2, '.', ''), $page->filter('[data-summary-tax-amount]')->text());
        self::assertStringContainsString($this->taxed(), $page->filter('.Summary-total')->text());
        self::assertCount(0, $page->filter('.TaxMention[data-tax-display="taxed"]'), 'Nothing on the page is left with its taxes.');
    }

    public function testTheCartOfTheDefaultGroupShowsTheLinesAndTheTotalTaxed(): void
    {
        $customer = $this->signedIn();
        $this->cartOf($customer);

        $page = $this->page('/checkout/cart');

        self::assertStringContainsString($this->taxed(), $page->filter('.CartItem-price')->first()->text());
        self::assertSame('taxed', $page->filter('.CartItem-price .TaxMention')->first()->attr('data-tax-display'));
        self::assertSame('taxed', $page->filter('.Summary-total .TaxMention')->attr('data-tax-display'));
        self::assertCount(0, $page->filter('.TaxMention[data-tax-display="untaxed"]'));
    }

    public function testTheCartButtonOfTheHeaderCarriesTheUntaxedTotalAndItsMention(): void
    {
        $this->cartOf($this->signedIn($this->untaxedGroup));

        self::assertMatchesRegularExpression('/\(€100\.00 excl\. tax\)/', $this->page('/')->text());
    }

    public function testTheCartButtonOfTheHeaderCarriesTheTaxedTotalAndItsMention(): void
    {
        $this->cartOf($this->signedIn());

        self::assertMatchesRegularExpression('/\(€'.preg_quote($this->taxed(), '/').' incl\. tax\)/', $this->page('/')->text());
    }

    public function testAnOrderSoldUntaxedStaysUntaxedOnceItsBuyerHasMovedToTheDefaultGroup(): void
    {
        $customer = $this->signedIn($this->untaxedGroup);
        $order = $this->placedOrder($customer);

        // The buyer is now shown prices with their taxes: the order keeps the way it was sold.
        $customer->setCustomerGroupId(CustomerGroupQuery::findDefault()->getId())->save($this->getPropelConnection());

        $page = $this->page('/account/order/'.$order->getId());

        // The goods 100 and the delivery, 6 with 1 of taxes, make 105 without them, 1 of taxes, 106 to pay.
        $lines = $page->filter('.Summary-line')->each(static fn (Crawler $line): string => trim((string) preg_replace('/\s+/u', ' ', $line->text())));
        self::assertContains('Total excl. tax €105.00', $lines);
        self::assertContains('VAT €1.00', $lines);
        self::assertSame('Total incl. tax to pay €106.00', trim((string) preg_replace('/\s+/u', ' ', $page->filter('.Summary-total')->text())));
        self::assertCount(0, $page->filter('.Summary .TaxMention[data-tax-display="taxed"]'));
    }

    public function testAnOrderSoldTaxedShowsItsTaxedTotal(): void
    {
        $customer = $this->signedIn();
        $order = $this->placedOrder($customer);

        $page = $this->page('/account/order/'.$order->getId());

        self::assertSame('taxed', $page->filter('.Summary-total .TaxMention')->attr('data-tax-display'));
        self::assertMatchesRegularExpression('/106\.00/', $page->filter('.Summary-total')->text());
    }

    /**
     * An order with a discount, a delivery and a line bought on promotion, sold untaxed: the figures of
     * the summary are the ones of the core, the taxes included, to the cent.
     */
    public function testTheSummaryOfAnUntaxedOrderWithADiscountAndAPromotionStatesTheFiguresOfTheCore(): void
    {
        $customer = $this->signedIn($this->untaxedGroup);
        $order = $this->orderWithADiscountAndAPromotion($customer);
        $tax = 0.0;
        $toPay = $order->getTotalAmount($tax);

        $page = $this->page('/account/order/'.$order->getId());
        $lines = $this->summaryLines($page);

        self::assertContains('Total excl. tax €'.number_format($order->getUntaxedTotalAmount(), 2, '.', ''), $lines);
        self::assertContains('VAT €'.number_format($tax, 2, '.', ''), $lines);
        self::assertSame('Total incl. tax to pay €'.number_format($toPay, 2, '.', ''), $this->normalized($page->filter('.Summary-total')->text()));
        // Goods 100 + 2 x 30, 20 + 2 x 6 of taxes, less 12 of discount, delivery 6 (1 of taxes): 186 to pay.
        self::assertEqualsWithDelta(186.0, $toPay, 0.001);
        self::assertContains('Delivery €5.00 excl. tax', $lines);
        self::assertContains('Discount −€'.number_format($order->getUntaxedDiscount(), 2, '.', '').' excl. tax', $lines);
        self::assertSame(
            number_format($toPay, 2, '.', ''),
            number_format($order->getUntaxedTotalAmount() + $tax, 2, '.', ''),
            'The total without taxes and the taxes add up to what is to pay.',
        );
    }

    public function testTheLinesOfAnUntaxedOrderAreChargedWithoutTaxesAndTheOnesOfATaxedOrderWithThem(): void
    {
        $untaxedCustomer = $this->signedIn($this->untaxedGroup);
        $untaxedOrder = $this->orderWithADiscountAndAPromotion($untaxedCustomer);
        $untaxedPrices = $this->prices($this->page('/account/order/'.$untaxedOrder->getId()));

        // The line on promotion is charged 30 untaxed, the other 100.
        self::assertStringContainsString('€30.00 excl. tax', $untaxedPrices);
        self::assertStringContainsString('€100.00 excl. tax', $untaxedPrices);

        $taxedCustomer = $this->signedIn();
        $taxedOrder = $this->orderWithADiscountAndAPromotion($taxedCustomer);
        $taxedPage = $this->page('/account/order/'.$taxedOrder->getId());
        $taxedPrices = $this->prices($taxedPage);

        // Taxed: the price paid, the promotion and its tax rows (30 + 6), and what the line cost before it, struck.
        self::assertStringContainsString('€36.00 incl. tax', $taxedPrices);
        self::assertStringContainsString('€120.00 incl. tax', $taxedPrices);
        self::assertSame('€58.80', $this->normalized($taxedPage->filter('.ProductCard-originalPrice')->text()));
        self::assertSame('taxed', $taxedPage->filter('.Summary-total .TaxMention')->attr('data-tax-display'));
    }

    public function testTheSummaryOfATaxedOrderKeepsItsSingleTotalAndItsTaxesLine(): void
    {
        $customer = $this->signedIn();
        $order = $this->orderWithADiscountAndAPromotion($customer);
        $tax = 0.0;
        $toPay = $order->getTotalAmount($tax);

        $page = $this->page('/account/order/'.$order->getId());

        self::assertSame('Total €'.number_format($toPay, 2, '.', '').' incl. tax', $this->normalized($page->filter('.Summary-total')->text()));
        self::assertCount(0, $page->filter('.Summary-line:contains("Total excl. tax")'));
        self::assertStringContainsString('Taxes of €'.number_format($tax, 2, '.', '').' included', $this->normalized($page->filter('.Summary-footer')->text()));
    }

    public function testTheOrderListShowsTheTotalOfEachOrderInTheDisplayItWasSoldUnder(): void
    {
        $customer = $this->signedIn($this->untaxedGroup);
        $this->placedOrder($customer);
        $customer->setCustomerGroupId(CustomerGroupQuery::findDefault()->getId())->save($this->getPropelConnection());

        $total = $this->page('/account/orders')->filter('.OrderCard .paragraph-1');

        self::assertMatchesRegularExpression('/105\.00/', $total->text());
        self::assertSame('untaxed', $total->filter('.TaxMention')->attr('data-tax-display'));
    }

    /**
     * The summary of an order page, one string per line.
     *
     * @return list<string>
     */
    private function summaryLines(Crawler $page): array
    {
        return $page->filter('.Summary-line')->each(fn (Crawler $line): string => $this->normalized($line->text()));
    }

    /**
     * What each line of an order page was charged, one string per line.
     */
    private function prices(Crawler $page): string
    {
        return implode(' | ', $page->filter('.ProductCard-price')->each(fn (Crawler $price): string => $this->normalized($price->text())));
    }

    private function normalized(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $text)));
    }

    private function signedIn(?CustomerGroup $group = null): Customer
    {
        $customer = $this->factory->customer(
            $this->factory->customerTitle(),
            null === $group ? [] : ['customerGroup' => $group],
        );
        $this->injector->setCustomer($customer);

        return $customer;
    }

    private function page(string $url): Crawler
    {
        $this->assertPageRenders($url);

        $page = $this->client->getCrawler();

        // The text of a price, read out of the page, keeps its amount and its mention apart on every page.
        self::assertDoesNotMatchRegularExpression('/[0-9](incl|excl)\. tax/', $page->text(''), 'A mention is stuck to its amount.');

        return $page;
    }

    private function cartOf(Customer $customer): void
    {
        $cart = $this->factory->cart($customer);
        $this->factory->cartItem($cart, $this->product, null, ['price' => '100.000000', 'promoPrice' => '100.000000']);
        $this->injector->setCart($cart);
    }

    /**
     * An order of 100 of goods and 6 of delivery (1 of it taxes), sold with the display of the group
     * the customer belongs to when it is placed.
     */
    private function placedOrder(Customer $customer): Order
    {
        $order = $this->factory->order($customer, ['statusCode' => OrderStatus::CODE_PAID, 'postage' => 6, 'postageTax' => 1]);
        $order->setCustomerGroupDisplayTaxedPrice($customer->getCustomerGroup()->getDisplayTaxedPrice())->save($this->getPropelConnection());
        $this->factory->orderProduct($order, ['title' => 'Tax display line', 'price' => '100.000000', 'promoPrice' => '100.000000', 'productSaleElementsId' => $this->product->getDefaultSaleElements()->getId()]);

        return $order;
    }

    /**
     * An order of two lines, one of them bought on promotion, with a discount of 12 and a delivery of 6
     * (1 of it taxes), sold with the display of the group the customer belongs to when it is placed:
     * the line of 100 carries 20 of taxes, the one of 2 x 30 (49 before its promotion) 2 x 6.
     */
    private function orderWithADiscountAndAPromotion(Customer $customer): Order
    {
        $connection = $this->getPropelConnection();
        $order = $this->factory->order($customer, ['statusCode' => OrderStatus::CODE_PAID, 'postage' => 6, 'postageTax' => 1]);
        $order->setCustomerGroupDisplayTaxedPrice($customer->getCustomerGroup()->getDisplayTaxedPrice())->setDiscount('12')->save($connection);
        $pseId = $this->product->getDefaultSaleElements()->getId();

        $full = $this->factory->orderProduct($order, ['title' => 'Full price line', 'price' => '100.000000', 'promoPrice' => '100.000000', 'productSaleElementsId' => $pseId]);
        $promoted = $this->factory->orderProduct($order, ['title' => 'Promoted line', 'price' => '49.000000', 'promoPrice' => '30.000000', 'quantity' => 2, 'productSaleElementsId' => $pseId]);
        $promoted->setWasInPromo(1)->save($connection);

        foreach ([[$full, '20', '20'], [$promoted, '9.8', '6']] as [$line, $amount, $promoAmount]) {
            (new OrderProductTax())
                ->setOrderProductId($line->getId())
                ->setTitle('VAT')
                ->setAmount($amount)
                ->setPromoAmount($promoAmount)
                ->save($connection);
        }

        return $order;
    }

    /**
     * What the tax engine adds to the 100 of the product for the visitor: the figure the page
     * must show a buyer shown prices with their taxes.
     */
    private function taxed(): string
    {
        $country = $this->getService(TaxEngine::class)->getDeliveryCountry();

        $taxed = number_format($this->product->getTaxedPrice($country, 100.0), 2, '.', '');

        // Without a tax on the product, the two displays would print the same figure and prove nothing.
        self::assertNotSame('100.00', $taxed, 'The tax rule of the test shop must tax the product.');

        return $taxed;
    }
}
