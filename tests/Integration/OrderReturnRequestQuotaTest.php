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

use FlexyBundle\Exception\TooManyReturnRequestsException;
use FlexyBundle\Service\OrderReturnRequestService;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Api\Service\OrderReturnHydrator;
use Thelia\Domain\OrderReturn\Exception\ReturnNotAllowedException;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Domain\OrderReturn\Service\ReturnRequestLimiter;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderReturnQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The quota of return requests counts the returns a customer opens, not the mistakes made
 * filling the form in: it is spent once the request has been accepted, never before.
 * Runs on an installed shop (bin/test-prepare).
 */
final class OrderReturnRequestQuotaTest extends IntegrationTestCase
{
    private ?string $previousEnabled = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousEnabled = ConfigQuery::read(ReturnEligibilityChecker::ENABLED_CONFIG_KEY);
        ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, '1');
    }

    protected function tearDown(): void
    {
        ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, (string) $this->previousEnabled);

        parent::tearDown();
    }

    public function testARequestTheCoreRefusesDoesNotSpendTheQuota(): void
    {
        [$customer, $order, $line] = $this->paidOrderWithOneLine(1.0);
        $limiter = $this->createMock(ReturnRequestLimiter::class);
        $limiter->expects(self::never())->method('allows');

        try {
            $this->service($limiter)->open($order, $customer, [(int) $line->getId() => 5.0], null, 'refund', null);
            self::fail('More than the ordered quantity was expected to be refused.');
        } catch (ReturnNotAllowedException) {
            self::assertFalse(OrderReturnQuery::create()->filterByOrderId($order->getId())->exists());
        }
    }

    public function testAnAcceptedRequestSpendsTheQuotaOnce(): void
    {
        [$customer, $order, $line] = $this->paidOrderWithOneLine(2.0);
        $limiter = $this->createMock(ReturnRequestLimiter::class);
        $limiter->expects(self::once())->method('allows')->with($customer)->willReturn(true);

        $return = $this->service($limiter)->open($order, $customer, [(int) $line->getId() => 1.0], null, 'refund', null);

        self::assertSame((int) $order->getId(), $return->getOrderId());
    }

    public function testAnAcceptedRequestOverTheQuotaIsRefusedAndNothingIsWritten(): void
    {
        [$customer, $order, $line] = $this->paidOrderWithOneLine(2.0);
        $limiter = $this->createMock(ReturnRequestLimiter::class);
        $limiter->expects(self::once())->method('allows')->willReturn(false);

        try {
            $this->service($limiter)->open($order, $customer, [(int) $line->getId() => 1.0], null, 'refund', null);
            self::fail('The quota was expected to refuse the request.');
        } catch (TooManyReturnRequestsException) {
            self::assertFalse(OrderReturnQuery::create()->filterByOrderId($order->getId())->exists());
        }
    }

    private function service(ReturnRequestLimiter $limiter): OrderReturnRequestService
    {
        $container = self::getContainer();

        return new OrderReturnRequestService(
            $container->get(ReturnEligibilityChecker::class),
            $container->get(OrderReturnHydrator::class),
            $container->get(EventDispatcherInterface::class),
            $limiter,
        );
    }

    /**
     * @return array{Customer, Order, OrderProduct}
     */
    private function paidOrderWithOneLine(float $quantity): array
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $customer = $factory->customer($factory->customerTitle());
        $order = $factory->order($customer, ['statusCode' => OrderStatus::CODE_PAID]);

        $reference = 'QUOTA-LINE-'.$order->getId();

        $orderProduct = new OrderProduct();
        $orderProduct->setOrderId($order->getId());
        $orderProduct->setProductRef($reference);
        $orderProduct->setProductSaleElementsRef($reference.'-PSE');
        $orderProduct->setTitle('A chair to send back');
        $orderProduct->setQuantity($quantity);
        $orderProduct->setPrice('100.000000');
        $orderProduct->setPromoPrice('0.000000');
        $orderProduct->setWasNew(0);
        $orderProduct->setWasInPromo(0);
        $orderProduct->setVirtual(0);
        $orderProduct->save($this->getPropelConnection());

        return [$customer, $order, $orderProduct];
    }
}
