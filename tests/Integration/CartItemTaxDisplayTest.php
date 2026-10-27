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

use FlexyBundle\Components\Organisms\CartItem\Base as CartItem;
use FlexyBundle\DTO\CartItemDto;
use FlexyBundle\Service\CartStockService;
use FlexyBundle\Service\PriceDisplay;
use FlexyBundle\Service\ProductSaleElementsService;
use FlexyBundle\Service\TaxDisplay;
use Thelia\Api\Service\DataAccess\AttributeAccessService;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Taxation\TaxEngine\TaxEngine;
use Thelia\Model\CartItem as CartItemModel;
use Thelia\Model\CartQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * A line of the cart is priced with or without its taxes according to the group of the buyer. A
 * gift a promotion gives away carries the discount of the promotion in both forms, as the core
 * publishes them: given outright it falls to zero, given at a discount it is read in the display
 * of the buyer.
 */
final class CartItemTaxDisplayTest extends IntegrationTestCase
{
    public function testALineIsPricedUntaxedForAnUntaxedGroup(): void
    {
        $this->signInUntaxed();

        $item = $this->mount($this->line(), []);

        self::assertSame(TaxDisplay::Untaxed, $item->prices['price']->display);
        self::assertSame(100.0, $item->prices['price']->amount);
        self::assertSame(TaxDisplay::Untaxed, $item->prices['promoPrice']->display);
    }

    public function testALineIsPricedTaxedForTheDefaultGroup(): void
    {
        $item = $this->mount($this->line(), []);

        self::assertSame(TaxDisplay::Taxed, $item->prices['price']->display);
        self::assertGreaterThan(100.0, $item->prices['price']->amount);
    }

    public function testAGiftGivenOutrightFallsToZeroUntaxed(): void
    {
        $this->signInUntaxed();
        $gift = $this->line(offered: true);

        // The core publishes the figures of the line in both forms: the untaxed ones are read as they are.
        $item = $this->mount($gift, $this->figuresOf($gift, discountShare: 1.0));

        self::assertTrue($item->offeredIsDiscounted);
        self::assertSame(TaxDisplay::Untaxed, $item->prices['offeredEffective']->display);
        self::assertSame(0.0, $item->prices['offeredEffective']->amount);
        self::assertSame(TaxDisplay::Untaxed, $item->prices['offered']->display);
        self::assertSame(100.0, $item->prices['offered']->amount);
    }

    public function testAGiftGivenAtADiscountIsReadUntaxedForAnUntaxedGroup(): void
    {
        $this->signInUntaxed();
        $gift = $this->line(offered: true);

        $item = $this->mount($gift, $this->figuresOf($gift, discountShare: 0.5));

        self::assertTrue($item->offeredIsDiscounted);
        self::assertSame(TaxDisplay::Untaxed, $item->prices['offeredEffective']->display);
        self::assertSame(TaxDisplay::Untaxed, $item->prices['offered']->display);
        self::assertEqualsWithDelta(50.0, $item->prices['offeredEffective']->amount, 0.001);
        self::assertEqualsWithDelta(100.0, $item->prices['offered']->amount, 0.001);
    }

    public function testAGiftGivenAtADiscountIsReadTaxedForTheDefaultGroup(): void
    {
        $gift = $this->line(offered: true);
        $figures = $this->figuresOf($gift, discountShare: 0.5);

        $item = $this->mount($gift, $figures);

        self::assertSame(TaxDisplay::Taxed, $item->prices['offeredEffective']->display);
        self::assertEqualsWithDelta($figures['lineTotalAfterDiscountTaxed'], $item->prices['offeredEffective']->amount, 0.001);
        self::assertEqualsWithDelta($figures['lineTotalTaxed'], $item->prices['offered']->amount, 0.001);
    }

    public function testAGiftThePromotionDoesNotDiscountIsNotStruck(): void
    {
        $gift = $this->line(offered: true);

        $item = $this->mount($gift, $this->figuresOf($gift, discountShare: 0.0));

        self::assertFalse($item->offeredIsDiscounted);
    }

    public function testTheLineReadsTheSixFiguresTheCorePublishes(): void
    {
        // What attr('cart', 'cart_items') publishes per line, in the spelling of the data access layer.
        $line = CartItemDto::fromArray([
            'offered_effective_taxed' => 12.0,
            'offered_effective_untaxed' => 10.0,
            'line_total_taxed' => 24.0,
            'line_total_untaxed' => 20.0,
            'line_total_after_discount_taxed' => 12.0,
            'line_total_after_discount_untaxed' => 10.0,
        ]);

        self::assertSame(12.0, $line->offeredEffectiveTaxed);
        self::assertSame(10.0, $line->offeredEffectiveUntaxed);
        self::assertSame(24.0, $line->lineTotalTaxed);
        self::assertSame(20.0, $line->lineTotalUntaxed);
        self::assertSame(12.0, $line->lineTotalAfterDiscountTaxed);
        self::assertSame(10.0, $line->lineTotalAfterDiscountUntaxed);
    }

    public function testTheCartPassesTheFiguresOfTheCoreToItsLines(): void
    {
        $factory = $this->createFixtureFactory();
        $currency = CurrencyQuery::create()->findOneByByDefault(1) ?? $factory->currency(['byDefault' => 1]);
        $product = $factory->product($factory->category(), $factory->taxRule(), $currency, ['title' => 'Helmet', 'locale' => 'en_US', 'basePrice' => 100.0, 'baseQuantity' => 10]);
        $cart = $factory->cart(null, ['currency' => $currency]);
        $factory->cartItem($cart, $product, null, ['price' => '100.000000', 'promoPrice' => '100.000000']);
        $stack = static::getContainer()->get('request_stack');
        $stack->getMainRequest()->getSession()->setSessionCart(CartQuery::create()->findPk($cart->getId()));

        $component = static::getContainer()->get('ux.twig_component.component_factory')->create('Organisms:Cart:Base', [])->getComponent();
        $line = $component->items[0];

        // Read from the core: nothing the theme could have worked out from the columns (a quantity of one, the
        // price of the line) is checked here, only that what the core calls the line is what the line carries.
        $published = $this->getService(AttributeAccessService::class)->attributeCart('cart_items')[0];

        self::assertSame($published['line_total_untaxed'], $line->lineTotalUntaxed);
        self::assertSame($published['line_total_taxed'], $line->lineTotalTaxed);
        self::assertSame($published['line_total_after_discount_untaxed'], $line->lineTotalAfterDiscountUntaxed);
        self::assertSame($published['line_total_after_discount_taxed'], $line->lineTotalAfterDiscountTaxed);
        self::assertSame($published['offered_effective_untaxed'], $line->offeredEffectiveUntaxed);
        self::assertSame($published['offered_effective_taxed'], $line->offeredEffectiveTaxed);
    }

    private function signInUntaxed(): void
    {
        $factory = $this->createFixtureFactory();
        $customer = $factory->customer($factory->customerTitle(), ['customerGroup' => $factory->customerGroup(null, 0.0, false)]);

        $session = static::getContainer()->get('request_stack')->getMainRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);
        $session->setCustomerUser($customer);
    }

    private function line(bool $offered = false): CartItemModel
    {
        $factory = $this->createFixtureFactory();
        $currency = CurrencyQuery::create()->findOneByByDefault(1) ?? $factory->currency(['byDefault' => 1]);
        $product = $factory->product($factory->category(), $factory->taxRule(), $currency, ['title' => 'Helmet', 'locale' => 'en_US', 'basePrice' => 100.0, 'baseQuantity' => 10]);

        return $factory->cartItem($factory->cart(null, ['currency' => $currency]), $product, null, [
            'price' => '100.000000',
            'promoPrice' => '100.000000',
            'isOffered' => $offered ? 1 : 0,
        ]);
    }

    /**
     * The figures the core publishes for a line of 100 untaxed: a share of the line taken off it.
     *
     * @return array<string, float>
     */
    private function figuresOf(CartItemModel $line, float $discountShare): array
    {
        $taxedTotal = $this->taxedTotalOf($line);

        return [
            'offeredEffectiveTaxed' => $taxedTotal * $discountShare,
            'offeredEffectiveUntaxed' => 100.0 * $discountShare,
            'lineTotalTaxed' => $taxedTotal,
            'lineTotalUntaxed' => 100.0,
            'lineTotalAfterDiscountTaxed' => $taxedTotal * (1 - $discountShare),
            'lineTotalAfterDiscountUntaxed' => 100.0 * (1 - $discountShare),
        ];
    }

    private function taxedTotalOf(CartItemModel $line): float
    {
        return $line->getTotalRealTaxedPrice($this->getService(TaxEngine::class)->getDeliveryCountry());
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function mount(CartItemModel $line, array $extra): CartItem
    {
        $langService = self::createStub(LangService::class);
        $langService->method('getLocale')->willReturn('en_US');

        $item = new CartItem(
            $this->getService(ProductSaleElementsService::class),
            $this->getService(TaxEngine::class),
            $langService,
            new CartStockService(),
            $this->getService(PriceDisplay::class),
        );

        $item->mount(CartItemDto::fromArray([
            'id' => $line->getId(),
            'cartId' => $line->getCartId(),
            'productId' => $line->getProductId(),
            'productSaleElementsId' => $line->getProductSaleElementsId(),
            'quantity' => 1,
            'stock' => 10,
            'stockManaged' => false,
            'isOffered' => (bool) $line->getIsOffered(),
            'title' => '',
        ] + $extra));

        return $item;
    }
}
