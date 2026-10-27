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
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductTax;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\ApiTestCase;

/**
 * A guest reads an order through a tracking link and an account holder reads it through
 * `/api/front/account/orders/{id}`: for the same order the two show the same figures. The view
 * of the guest asks the model of the core for them instead of working them out, and this test is
 * what holds it there: it reads the order from the API as the buyer does and compares, key by key,
 * every amount the view publishes.
 */
final class GuestOrderViewMatchesTheApiTest extends ApiTestCase
{
    public function testEveryAmountOfTheViewIsTheOneTheApiPublishes(): void
    {
        [$order, $token] = $this->orderOfAnAccount();

        $view = (new GuestOrderView())->build($order);
        $api = $this->apiOrder($order, $token);

        $amounts = array_filter($view, static fn (mixed $value, string $key): bool => \is_float($value) && 'id' !== $key, \ARRAY_FILTER_USE_BOTH);

        // The figures that matter to a recap are all there: a view that stopped publishing one would pass otherwise.
        foreach (['totalAmount', 'totalAmountWithoutTaxes', 'amountDiscountWithTaxes', 'amountDiscountWithoutTaxes', 'totalAmountWithTaxesAfterDiscount', 'totalAmountWithoutTaxesAfterDiscount', 'totalAmountWithoutTaxesWithShipping', 'totalShippingWithTaxes', 'totalShippingWithoutTaxes', 'totalTaxAmount', 'totalGoodsWithoutTaxes', 'postageTax'] as $key) {
            self::assertArrayHasKey($key, $amounts, $key.' is missing from the view of the guest.');
        }

        foreach ($amounts as $key => $amount) {
            self::assertArrayHasKey($key, $api, \sprintf('The view publishes %s, the API does not: a figure of the view is not the core\'s.', $key));
            self::assertEqualsWithDelta($api[$key], $amount, 1e-9, $key);
        }

        // The tax display frozen on the order is not a float: it is compared on its own.
        self::assertArrayHasKey('customerGroupDisplayTaxedPrice', $api, 'The API does not publish the tax display frozen on the order.');
        self::assertSame($api['customerGroupDisplayTaxedPrice'], $view['customerGroupDisplayTaxedPrice'], 'customerGroupDisplayTaxedPrice');

        // The goods, 100 and 49 (30 on promotion), the gift wrapping, 2 of 3, less a discount of 12, and 6 of delivery.
        self::assertEqualsWithDelta(12.0, $api['amountDiscountWithTaxes'], 1e-9);
        self::assertEqualsWithDelta($api['totalAmountWithoutTaxesAfterDiscount'] + $api['totalShippingWithoutTaxes'], $api['totalAmountWithoutTaxesWithShipping'], 0.011);
    }

    public function testEveryLineOfTheViewIsTheOneTheApiPublishes(): void
    {
        [$order, $token] = $this->orderOfAnAccount();

        $view = (new GuestOrderView())->build($order);
        $api = $this->apiOrder($order, $token);

        $apiLines = [];

        foreach ($api['orderProducts'] as $apiLine) {
            $apiLines[$apiLine['title']] = $apiLine;
        }

        self::assertCount(3, $view['orderProducts']);
        self::assertCount(3, $apiLines);

        foreach ($view['orderProducts'] as $line) {
            $apiLine = $apiLines[$line['title']] ?? self::fail('The API does not publish the line '.$line['title']);

            foreach (['quantity', 'price', 'promoPrice', 'unitTaxedPrice', 'taxedPromoPrice', 'unitTaxedPriceBeforePromotion', 'lineTotalWithoutTaxes', 'lineTotalWithTaxes'] as $key) {
                self::assertArrayHasKey($key, $line, $key.' is missing from the line of the view.');
                self::assertArrayHasKey($key, $apiLine, $key.' is missing from the line of the API.');
                null === $line[$key]
                    ? self::assertNull($apiLine[$key], $line['title'].' '.$key)
                    : self::assertEqualsWithDelta($apiLine[$key], $line[$key], 1e-9, $line['title'].' '.$key);
            }
        }

        // A line charged twice as a service, as the order was invoiced: 3 untaxed and 3.6 taxed each.
        self::assertEqualsWithDelta(6.0, $apiLines['Gift wrapping']['lineTotalWithoutTaxes'], 1e-9);
        self::assertEqualsWithDelta(7.2, $apiLines['Gift wrapping']['lineTotalWithTaxes'], 1e-9);
    }

    /**
     * 100 of goods with 20 of taxes, a line on promotion (49 untaxed, 30 after it), a gift wrapping
     * bought twice, a discount of 12 and a delivery of 6 with 1 of taxes.
     *
     * @return array{Order, string}
     */
    private function orderOfAnAccount(): array
    {
        $fixtures = $this->createFixtureFactory();
        $customer = $fixtures->customer($fixtures->customerTitle(), ['password' => 'password']);
        $order = $fixtures->order($customer, ['statusCode' => OrderStatus::CODE_NOT_PAID, 'paymentModuleCode' => 'Cheque', 'postage' => 6, 'postageTax' => 1]);
        $order->setDiscount('12')->save();

        $line = $fixtures->orderProduct($order, ['title' => 'Tax display line', 'price' => '100.000000', 'promoPrice' => '100.000000', 'productSaleElementsId' => 999999999]);
        (new OrderProductTax())->setOrderProductId($line->getId())->setTitle('VAT')->setAmount('20')->setPromoAmount('20')->save();

        $promoted = $fixtures->orderProduct($order, ['title' => 'Promoted line', 'price' => '49.000000', 'promoPrice' => '30.000000', 'productSaleElementsId' => 999999999]);
        $promoted->setWasInPromo(1)->save();
        (new OrderProductTax())->setOrderProductId($promoted->getId())->setTitle('VAT')->setAmount('9.8')->setPromoAmount('6')->save();

        $wrapping = $fixtures->orderProduct($order, ['title' => 'Gift wrapping', 'price' => '3.000000', 'promoPrice' => '3.000000', 'quantity' => 2, 'lineType' => OrderProduct::LINE_TYPE_SERVICE, 'productSaleElementsId' => 999999999]);
        (new OrderProductTax())->setOrderProductId($wrapping->getId())->setTitle('VAT')->setAmount('0.6')->setPromoAmount('0.6')->save();

        // Read again: the lines of the order the factory built were loaded before the others existed.
        return [OrderQuery::create()->findPk($order->getId()), $this->authenticateAsCustomer($customer)];
    }

    /**
     * @return array<string, mixed>
     */
    private function apiOrder(Order $order, string $token): array
    {
        $response = $this->jsonRequest('GET', '/api/front/account/orders/'.$order->getId(), token: $token);
        self::assertJsonResponseSuccessful($response);

        return json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
