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

namespace FlexyBundle\Tests\Component\Checkout;

use FlexyBundle\Service\GuestOrderView;
use FlexyBundle\Service\PlacedOrderMemory;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Customer;
use Thelia\Model\CustomerGroupQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProductTax;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\IntegrationTestCase;

/**
 * An order is read the way it was sold: the confirmation of the order just placed, and the view a
 * guest reaches through a tracking link, show the total with or without the taxes according to the
 * display its buyer's group had at the placement.
 */
final class PlacedOrderTaxDisplayTest extends IntegrationTestCase
{
    public function testTheConfirmationRecapOfAnUntaxedOrderShowsItsUntaxedTotalWithTheMention(): void
    {
        $customer = $this->customer(untaxed: true);
        $order = $this->orderOfAHundredAndSixOfDelivery($customer);
        $this->placedOrderMemory()->remember($order);

        // The buyer is shown prices with their taxes by now: the order keeps the way it was sold.
        $customer->setCustomerGroupId(CustomerGroupQuery::findDefault()->getId())->save();

        $recap = $this->confirmationPage($customer)->filter('[data-testid="order-recap-total"]');

        // The goods, 100, and the delivery, 6 with 1 of taxes.
        self::assertMatchesRegularExpression('/105\.00/', $recap->text());
        self::assertSame('untaxed', $recap->nextAll()->filter('.TaxMention')->attr('data-tax-display'));
    }

    public function testTheConfirmationRecapOfATaxedOrderShowsItsTaxedTotalWithTheMention(): void
    {
        $customer = $this->customer(untaxed: false);
        $order = $this->orderOfAHundredAndSixOfDelivery($customer);
        $this->placedOrderMemory()->remember($order);

        $recap = $this->confirmationPage($customer)->filter('[data-testid="order-recap-total"]');

        self::assertMatchesRegularExpression('/106\.00/', $recap->text());
        self::assertSame('taxed', $recap->nextAll()->filter('.TaxMention')->attr('data-tax-display'));
    }

    public function testTheViewOfAGuestOrderCarriesTheDisplayItWasSoldUnder(): void
    {
        $untaxed = $this->orderOfAHundredAndSixOfDelivery($this->customer(untaxed: true));
        $taxed = $this->orderOfAHundredAndSixOfDelivery($this->customer(untaxed: false));

        $view = new GuestOrderView();

        self::assertFalse($view->build($untaxed)['customerGroupDisplayTaxedPrice']);
        self::assertTrue($view->build($taxed)['customerGroupDisplayTaxedPrice']);
        // The tax of the delivery travels with it: the untaxed delivery is the difference.
        self::assertSame(1.0, $view->build($untaxed)['postageTax']);
    }

    public function testTheConfirmationRecapOfAnUntaxedOrderStatesItsTotalWithoutTaxesItsTaxesAndWhatIsToPay(): void
    {
        $customer = $this->customer(untaxed: true);
        $order = $this->orderWithADiscountAndTaxes($customer);
        $this->placedOrderMemory()->remember($order);
        $tax = 0.0;
        $toPay = $order->getTotalAmount($tax);

        $recap = $this->confirmationPage($customer)->filter('[data-testid="order-recap"] .Summary');
        $lines = $recap->filter('.Summary-line')->each(static fn (Crawler $line): string => trim((string) preg_replace('/\s+/u', ' ', $line->text())));

        self::assertContains('Total excl. tax €'.number_format($order->getUntaxedTotalAmount(), 2, '.', ''), $lines);
        self::assertContains('VAT €'.number_format($tax, 2, '.', ''), $lines);
        self::assertSame('Total incl. tax to pay €'.number_format($toPay, 2, '.', ''), trim((string) preg_replace('/\s+/u', ' ', $recap->filter('.Summary-total')->text())));
    }

    /**
     * 100 of goods with 20 of taxes, a discount of 12 and a delivery of 6 with 1 of taxes.
     */
    private function orderWithADiscountAndTaxes(Customer $customer): Order
    {
        $order = $this->orderOfAHundredAndSixOfDelivery($customer);
        $order->setDiscount('12')->save();
        $line = $order->getOrderProducts()->getFirst();
        (new OrderProductTax())->setOrderProductId($line->getId())->setTitle('VAT')->setAmount('20')->setPromoAmount('20')->save();

        // A line bought on promotion: 49 untaxed before it (9.8 of taxes), 30 after (6 of taxes).
        $promoted = $this->createFixtureFactory()->orderProduct($order, ['title' => 'Promoted line', 'price' => '49.000000', 'promoPrice' => '30.000000', 'productSaleElementsId' => 999999999]);
        $promoted->setWasInPromo(1)->save();
        (new OrderProductTax())->setOrderProductId($promoted->getId())->setTitle('VAT')->setAmount('9.8')->setPromoAmount('6')->save();

        // Read again: the lines of the order the factory built were loaded before the second one existed.
        return OrderQuery::create()->findPk($order->getId());
    }

    private function confirmationPage(Customer $customer): Crawler
    {
        $session = $this->session();
        $session->setCustomerUser($customer);
        $request = Request::create('/checkout/confirm');
        $request->setSession($session);

        $response = static::$kernel->handle($request, HttpKernelInterface::SUB_REQUEST);
        self::assertSame(200, $response->getStatusCode());

        return new Crawler((string) $response->getContent());
    }

    private function session(): Session
    {
        $session = static::getContainer()->get('request_stack')->getMainRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);

        return $session;
    }

    private function placedOrderMemory(): PlacedOrderMemory
    {
        return new PlacedOrderMemory(static::getContainer()->get('request_stack'));
    }

    private function customer(bool $untaxed): Customer
    {
        $fixtures = $this->createFixtureFactory();

        return $fixtures->customer(
            $fixtures->customerTitle(),
            $untaxed ? ['customerGroup' => $fixtures->customerGroup(null, 0.0, false)] : [],
        );
    }

    private function orderOfAHundredAndSixOfDelivery(Customer $customer): Order
    {
        $fixtures = $this->createFixtureFactory();
        $order = $fixtures->order($customer, ['statusCode' => OrderStatus::CODE_NOT_PAID, 'paymentModuleCode' => 'Cheque', 'postage' => 6, 'postageTax' => 1]);
        $order->setCustomerGroupDisplayTaxedPrice($customer->getCustomerGroup()->getDisplayTaxedPrice())->save();
        $fixtures->orderProduct($order, ['title' => 'Tax display line', 'price' => '100.000000', 'promoPrice' => '100.000000', 'productSaleElementsId' => 999999999]);

        return $order;
    }
}
