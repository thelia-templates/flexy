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

namespace FlexyBundle\Tests\Unit\Service;

use FlexyBundle\Service\PriceDisplay;
use FlexyBundle\Service\TaxDisplay;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thelia\Api\Resource\DeliveryModuleOption;

/**
 * Which of the two figures the core published a buyer reads, and what a placed order says about
 * it. None of it needs the shop: the display of a visitor is the core's answer, asked of the
 * kernel in the page tests.
 */
final class PriceDisplayTest extends TestCase
{
    /**
     * @param array<string, mixed> $order
     */
    #[DataProvider('orders')]
    public function testAPlacedOrderIsReadTheWayItWasSold(array $order, TaxDisplay $expected): void
    {
        self::assertSame($expected, $this->priceDisplay()->forOrder($order));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, TaxDisplay}>
     */
    public static function orders(): iterable
    {
        yield 'sold untaxed' => [['customerGroupDisplayTaxedPrice' => false], TaxDisplay::Untaxed];
        yield 'sold taxed' => [['customerGroupDisplayTaxedPrice' => true], TaxDisplay::Taxed];
        yield 'placed before the groups existed' => [[], TaxDisplay::Taxed];
        // Whatever the discount: the core publishes both forms of it, there is nothing to fall back on.
        yield 'sold untaxed, with a discount' => [
            ['customerGroupDisplayTaxedPrice' => false, 'amountDiscountWithTaxes' => 12.0, 'amountDiscountWithoutTaxes' => 10.0],
            TaxDisplay::Untaxed,
        ];
        yield 'sold untaxed, with a discount whose untaxed form is not there' => [
            ['customerGroupDisplayTaxedPrice' => false, 'amountDiscountWithTaxes' => 12.0],
            TaxDisplay::Untaxed,
        ];
    }

    public function testTheUntaxedFiguresOfAnOrderAreTheOnesTheCorePublishesUntaxed(): void
    {
        $order = self::discountedOrder();

        $priceDisplay = $this->priceDisplay();

        // The goods after the discount, 90, and the delivery, 5: the total the core publishes, no sum of the theme.
        self::assertSame(95.0, $priceDisplay->orderTotal(TaxDisplay::Untaxed, $order)->amount);
        self::assertSame(5.0, $priceDisplay->orderShipping(TaxDisplay::Untaxed, $order)->amount);
        self::assertSame(10.0, $priceDisplay->orderDiscount(TaxDisplay::Untaxed, $order)->amount);
        self::assertSame(TaxDisplay::Untaxed, $priceDisplay->orderTotal(TaxDisplay::Untaxed, $order)->display);
    }

    public function testTheTaxesOfAnOrderAddUpWithItsUntaxedTotalToWhatIsToPay(): void
    {
        $order = self::discountedOrder();

        $priceDisplay = $this->priceDisplay();

        // The tax the core publishes: the goods after the discount and the delivery.
        self::assertSame(19.0, $priceDisplay->orderTaxAmount($order));
        self::assertSame(
            $priceDisplay->orderTotal(TaxDisplay::Taxed, $order)->amount,
            round($priceDisplay->orderTotal(TaxDisplay::Untaxed, $order)->amount + $priceDisplay->orderTaxAmount($order), 2),
        );
    }

    public function testTheTaxedTotalOfAnOrderIsTheOneTheCorePublishes(): void
    {
        $order = self::discountedOrder();

        $priceDisplay = $this->priceDisplay();

        self::assertSame(114.0, $priceDisplay->orderTotal(TaxDisplay::Taxed, $order)->amount);
        self::assertSame(6.0, $priceDisplay->orderShipping(TaxDisplay::Taxed, $order)->amount);
        self::assertSame(12.0, $priceDisplay->orderDiscount(TaxDisplay::Taxed, $order)->amount);
    }

    public function testAServiceLineOfAnOrderIsPricedForItsWholeQuantity(): void
    {
        // The unit prices say 4 and 4.8, the core charged 8 and 9.6 for the two: the theme reads the totals of the line.
        $line = ['price' => 4.0, 'unitTaxedPrice' => 4.8, 'quantity' => 2, 'lineTotalWithoutTaxes' => 8.0, 'lineTotalWithTaxes' => 9.6];

        $priceDisplay = $this->priceDisplay();

        self::assertSame(9.6, $priceDisplay->orderServiceLine(TaxDisplay::Taxed, $line)->amount);
        self::assertSame(8.0, $priceDisplay->orderServiceLine(TaxDisplay::Untaxed, $line)->amount);
    }

    public function testALineBoughtOnPromotionIsChargedItsPromotionalPriceInBothDisplays(): void
    {
        // 49 untaxed on sale for 30, the taxed price paid being the one of the promotion (36).
        $line = ['price' => 49.0, 'promoPrice' => 30.0, 'wasInPromo' => true, 'unitTaxedPrice' => 36.0, 'unitTaxedPriceBeforePromotion' => 58.8];

        $priceDisplay = $this->priceDisplay();

        self::assertSame(36.0, $priceDisplay->orderLineUnit(TaxDisplay::Taxed, $line)->amount);
        self::assertSame(30.0, $priceDisplay->orderLineUnit(TaxDisplay::Untaxed, $line)->amount);
        // What it cost before the promotion, struck beside it: both forms as the core publishes them.
        self::assertSame(58.8, $priceDisplay->orderLineOriginalUnit(TaxDisplay::Taxed, $line)->amount);
        self::assertSame(49.0, $priceDisplay->orderLineOriginalUnit(TaxDisplay::Untaxed, $line)->amount);
    }

    public function testALineNotOnPromotionIsChargedItsPriceInBothDisplays(): void
    {
        $line = ['price' => 25.99, 'promoPrice' => 20.0, 'wasInPromo' => false, 'unitTaxedPrice' => 31.19];

        $priceDisplay = $this->priceDisplay();

        self::assertSame(31.19, $priceDisplay->orderLineUnit(TaxDisplay::Taxed, $line)->amount);
        self::assertSame(25.99, $priceDisplay->orderLineUnit(TaxDisplay::Untaxed, $line)->amount);
    }

    public function testTheFigureAskedForIsTheOnlyOneConsidered(): void
    {
        $priceDisplay = $this->priceDisplay();

        self::assertSame(20.0, $priceDisplay->choose(TaxDisplay::Untaxed, 20.0, 24.0)?->amount);
        self::assertSame(24.0, $priceDisplay->choose(TaxDisplay::Taxed, 20.0, 24.0)?->amount);
        // No fall back on the other figure: it would be written under the wrong mention.
        self::assertNull($priceDisplay->choose(TaxDisplay::Untaxed, null, 24.0));
        self::assertNull($priceDisplay->choose(TaxDisplay::Taxed, 20.0, null));
    }

    /**
     * @param array{postage: float|null, tax: float|null, untaxed: float|null} $quote
     */
    #[DataProvider('quotes')]
    public function testACarrierQuoteIsReadWithOrWithoutItsTaxes(array $quote, ?float $taxed, ?float $untaxed): void
    {
        $option = (new DeliveryModuleOption())->setPostage($quote['postage'])->setPostageTax($quote['tax'])->setPostageUntaxed($quote['untaxed']);

        $priceDisplay = $this->priceDisplay();

        self::assertSame($taxed, $priceDisplay->ofDeliveryOption(TaxDisplay::Taxed, $option)?->amount);
        self::assertSame($untaxed, $priceDisplay->ofDeliveryOption(TaxDisplay::Untaxed, $option)?->amount);
    }

    /**
     * @return iterable<string, array{array{postage: float|null, tax: float|null, untaxed: float|null}, float|null, float|null}>
     */
    public static function quotes(): iterable
    {
        yield 'the carrier gives the untaxed amount' => [['postage' => 6.0, 'tax' => 1.0, 'untaxed' => 4.8], 6.0, 4.8];
        yield 'the core publishes both forms' => [['postage' => 6.0, 'tax' => 1.0, 'untaxed' => 5.0], 6.0, 5.0];
        // The core fills the untaxed postage whenever it fills the postage: where it does not, there is no
        // figure to print, and the theme does not take the tax out of the taxed one to make one.
        yield 'the untaxed postage is not there' => [['postage' => 6.0, 'tax' => 1.0, 'untaxed' => null], 6.0, null];
        yield 'no quote' => [['postage' => null, 'tax' => null, 'untaxed' => null], null, null];
    }

    public function testAProductIsPricedWithTheFigureOfTheDisplayTheApiPublished(): void
    {
        $priceDisplay = $this->priceDisplay();

        // The front API gives the price of a sale element with and without its taxes: the card prints one of them.
        self::assertSame(24.0, $priceDisplay->ofProduct(TaxDisplay::Taxed, 20.0, 24.0)?->amount);
        self::assertSame(20.0, $priceDisplay->ofProduct(TaxDisplay::Untaxed, 20.0, 24.0)?->amount);
        self::assertNull($priceDisplay->ofProduct(TaxDisplay::Taxed, 20.0, null));
    }

    public function testTheMentionOfEachDisplayIsAKeyOfTheCatalogue(): void
    {
        self::assertSame('incl. tax', TaxDisplay::Taxed->mention());
        self::assertSame('excl. tax', TaxDisplay::Untaxed->mention());
    }

    /**
     * An order of 100 of goods and 6 of delivery (1 of it tax), with a discount of 12 (10 untaxed),
     * as the core publishes it.
     *
     * @return array<string, mixed>
     */
    private static function discountedOrder(): array
    {
        return [
            'customerGroupDisplayTaxedPrice' => false,
            'totalAmount' => 114.0,
            'totalAmountWithoutTaxes' => 100.0,
            'totalAmountWithTaxesAfterDiscount' => 108.0,
            'totalAmountWithoutTaxesAfterDiscount' => 90.0,
            'totalAmountWithoutTaxesWithShipping' => 95.0,
            'totalShippingWithTaxes' => 6.0,
            'totalShippingWithoutTaxes' => 5.0,
            'postageTax' => 1.0,
            'totalTaxAmount' => 19.0,
            'amountDiscountWithTaxes' => 12.0,
            'amountDiscountWithoutTaxes' => 10.0,
        ];
    }

    private function priceDisplay(): PriceDisplay
    {
        // The visitor is the core's business; the order readers touch no collaborator.
        return (new \ReflectionClass(PriceDisplay::class))->newInstanceWithoutConstructor();
    }
}
