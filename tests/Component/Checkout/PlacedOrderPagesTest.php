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

use FlexyBundle\Controller\CheckoutController;
use FlexyBundle\Service\GuestOrderView;
use FlexyBundle\Service\PlacedOrderMemory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Customer;
use Thelia\Model\Lang;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\IntegrationTestCase;

/**
 * The pages that come after the placement act on the order this session placed, never on an order the address names:
 * the failure page cancels the order just placed only (a cheque or a transfer still waiting for its money stays as it
 * is), and the confirmation page shows a signed-in customer an order of their own only.
 *
 * Boots the host kernel on the test database `bin/test-prepare` creates.
 */
final class PlacedOrderPagesTest extends IntegrationTestCase
{
    public function testTheFailurePageCancelsTheOrderThisSessionJustPlaced(): void
    {
        $customer = $this->customer();
        $order = $this->unpaidOrder($customer);
        $this->placedOrderMemory()->remember($order);

        $this->failurePage($order, $customer);

        self::assertTrue($this->reloaded($order)->isCancelled());
    }

    public function testTheFailurePageLeavesAnotherUnpaidOrderOfTheCustomerAsItIs(): void
    {
        $customer = $this->customer();
        $waitingForACheque = $this->unpaidOrder($customer);
        $this->placedOrderMemory()->remember($this->unpaidOrder($customer));

        $this->failurePage($waitingForACheque, $customer);

        self::assertTrue($this->reloaded($waitingForACheque)->isNotPaid());
    }

    public function testTheFailurePageCancelsNothingForASessionThatPlacedNoOrder(): void
    {
        $customer = $this->customer();
        $order = $this->unpaidOrder($customer);

        $this->failurePage($order, $customer);

        self::assertTrue($this->reloaded($order)->isNotPaid());
    }

    public function testTheConfirmationShowsTheSignedInCustomerTheirOwnOrder(): void
    {
        $customer = $this->customer();
        $order = $this->unpaidOrder($customer);
        $this->placedOrderMemory()->remember($order);

        self::assertSame($order->getId(), $this->orderPlacedBy($customer)?->getId());
    }

    public function testTheConfirmationOfAnotherCustomersOrderIsRefused(): void
    {
        $order = $this->unpaidOrder($this->customer());
        $this->placedOrderMemory()->remember($order);

        self::assertNull($this->orderPlacedBy($this->customer()));
    }

    public function testTheConfirmationOfAnUnpaidOrderShowsItsRecapAndWaitsForThePayment(): void
    {
        $customer = $this->customer();
        $order = $this->unpaidOrder($customer);
        $fixtures = $this->createFixtureFactory();
        $product = $fixtures->product($fixtures->category(), $fixtures->taxRule(), $fixtures->currency(), ['title' => 'Recap line', 'locale' => Lang::getDefaultLanguage()->getLocale()]);
        $fixtures->orderProduct($order, [
            'quantity' => 2,
            'price' => '12.500000',
            'promoPrice' => '12.500000',
            'title' => 'Recap line',
            'productSaleElementsId' => $product->getDefaultSaleElements()->getId(),
        ]);
        $this->placedOrderMemory()->remember($order);

        $html = $this->confirmationPage($customer);

        self::assertStringContainsString($order->getRef(), $html);
        self::assertStringContainsString('data-testid="order-recap-total"', $html);
        self::assertMatchesRegularExpression('#data-testid="order-recap-total">[^<]*25#', $html);
        self::assertStringContainsString('Recap line', $html);
        self::assertStringContainsString('data-testid="order-recap-payment"', $html);
        self::assertStringContainsString('/account/order/'.$order->getId(), $html);
        self::assertStringContainsString('Your order has been placed', $html);
        self::assertStringContainsString('It is waiting for its payment.', $html);
        self::assertStringNotContainsString('Your payment has been confirmed', $html);
    }

    public function testTheConfirmationOfAPaidOrderSaysThePaymentIsConfirmed(): void
    {
        $customer = $this->customer();
        $order = $this->createFixtureFactory()->order($customer, ['statusCode' => OrderStatus::CODE_PAID, 'paymentModuleCode' => 'Cheque']);
        $this->placedOrderMemory()->remember($order);

        $html = $this->confirmationPage($customer);

        self::assertStringContainsString($order->getRef(), $html);
        self::assertStringContainsString('Your payment has been confirmed', $html);
        self::assertStringNotContainsString('It is waiting for its payment.', $html);
    }

    public function testTheConfirmationPageNeverShowsTheRecapOfAnotherCustomersOrder(): void
    {
        $order = $this->unpaidOrder($this->customer());
        $this->placedOrderMemory()->remember($order);

        $html = $this->confirmationPage($this->customer());

        self::assertStringNotContainsString($order->getRef(), $html);
        self::assertStringNotContainsString('data-testid="order-recap"', $html);
    }

    public function testALineWhoseProductIsGoneKeepsItsFrozenTitle(): void
    {
        $customer = $this->customer();
        $order = $this->unpaidOrder($customer);
        $this->createFixtureFactory()->orderProduct($order, ['title' => 'Gone line', 'productSaleElementsId' => 999999999]);
        $this->placedOrderMemory()->remember($order);

        self::assertStringContainsString('Gone line', $this->confirmationPage($customer));
    }

    public function testTheAccountOrderPageAndTheTrackingViewKeepTheCurrencyOfTheOrder(): void
    {
        $customer = $this->customer();
        $fixtures = $this->createFixtureFactory();
        $order = $this->unpaidOrder($customer);
        $order->setCurrencyId($fixtures->currency(['code' => 'USD', 'symbol' => '$'])->getId())->save();
        $fixtures->orderProduct($order, ['title' => 'Dollar line', 'price' => '12.500000', 'promoPrice' => '12.500000', 'productSaleElementsId' => 999999999]);

        self::assertSame('USD', (new GuestOrderView())->build($order)['currencyCode']);

        $session = $this->session();
        $session->setCustomerUser($customer);
        $request = Request::create('/account/order/'.$order->getId());
        $request->setSession($session);
        $response = static::$kernel->handle($request, HttpKernelInterface::SUB_REQUEST);

        self::assertSame(200, $response->getStatusCode());
        // The header may offer the euro among the currencies to browse in: the amounts are what is looked at.
        self::assertMatchesRegularExpression('/\$\s?12\.50/', (string) $response->getContent());
        self::assertDoesNotMatchRegularExpression('/€\s?12\.50/', (string) $response->getContent());
    }

    private function confirmationPage(Customer $customer): string
    {
        $session = $this->session();
        $session->setCustomerUser($customer);
        $request = Request::create('/checkout/confirm');
        $request->setSession($session);

        $response = static::$kernel->handle($request, HttpKernelInterface::SUB_REQUEST);
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getContent();
    }

    private function failurePage(Order $order, Customer $customer): void
    {
        $session = $this->session();
        $session->setCustomerUser($customer);
        $request = Request::create('/checkout/failed?order_id='.$order->getId());
        $request->setSession($session);

        static::$kernel->handle($request, HttpKernelInterface::SUB_REQUEST);
    }

    private function orderPlacedBy(Customer $customer): ?Order
    {
        $session = $this->session();
        $session->setCustomerUser($customer);
        $controller = static::getContainer()->get(CheckoutController::class);

        return (new \ReflectionMethod($controller, 'orderPlacedBy'))->invoke($controller, $session, $this->placedOrderMemory());
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

    private function customer(): Customer
    {
        $fixtures = $this->createFixtureFactory();

        return $fixtures->customer($fixtures->customerTitle());
    }

    private function unpaidOrder(Customer $customer): Order
    {
        return $this->createFixtureFactory()->order($customer, ['statusCode' => OrderStatus::CODE_NOT_PAID, 'paymentModuleCode' => 'Cheque']);
    }

    private function reloaded(Order $order): Order
    {
        $reloaded = OrderQuery::create()->findPk($order->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }
}
