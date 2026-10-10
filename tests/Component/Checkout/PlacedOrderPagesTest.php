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
use FlexyBundle\Service\PlacedOrderMemory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Customer;
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
