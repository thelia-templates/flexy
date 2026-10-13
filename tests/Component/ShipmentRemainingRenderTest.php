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

namespace FlexyBundle\Tests\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\ComponentRendererInterface;

/** What an order shipped in several still owes the customer, as the order page shows it. */
final class ShipmentRemainingRenderTest extends KernelTestCase
{
    /**
     * @param array<string, mixed> $order
     */
    private function render(array $order): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender('Organisms:Shipment:Remaining', ['order' => $order]);
    }

    /**
     * Three lines ordered, one parcel gone with part of them, one draft cancelled.
     *
     * @return array<string, mixed>
     */
    private function partlyShippedOrder(): array
    {
        return [
            'orderProducts' => [
                ['id' => 11, 'title' => 'Mesh shoes', 'productRef' => 'SHOE-42', 'quantity' => 3, 'virtual' => false, 'lineType' => 'product'],
                ['id' => 12, 'title' => 'Socks', 'productRef' => 'SOCK-7', 'quantity' => 2, 'virtual' => false, 'lineType' => 'product'],
                ['id' => 13, 'title' => 'Rain jacket', 'productRef' => 'JACKET-3', 'quantity' => 1, 'virtual' => false, 'lineType' => 'product'],
                ['id' => 14, 'title' => 'Gift wrapping', 'productRef' => 'WRAP', 'quantity' => 1, 'virtual' => false, 'lineType' => 'service'],
                ['id' => 15, 'title' => 'E-book', 'productRef' => 'EBOOK', 'quantity' => 1, 'virtual' => true, 'lineType' => 'product'],
            ],
            'orderShipments' => [
                ['status' => 'shipped', 'orderShipmentItems' => [
                    ['quantity' => 2, 'orderProduct' => ['id' => 11]],
                    ['quantity' => 2, 'orderProduct' => ['id' => 12]],
                ]],
                ['status' => 'cancelled', 'orderShipmentItems' => [
                    ['quantity' => 1, 'orderProduct' => ['id' => 13]],
                ]],
            ],
        ];
    }

    public function testWhatAParcelLeftBehindIsListedWithTheQuantityStillOwed(): void
    {
        $html = $this->render($this->partlyShippedOrder());

        self::assertStringContainsString('data-testid="order-shipment-remaining"', $html);
        self::assertStringContainsString('Still to be shipped', $html);
        self::assertMatchesRegularExpression('#1 &times;.*Mesh shoes#s', $html, 'three ordered, two gone: one owed');
        self::assertMatchesRegularExpression('#1 &times;.*Rain jacket#s', $html, 'the cancelled draft holds nothing');
        self::assertStringNotContainsString('Socks', $html);
        self::assertStringNotContainsString('Gift wrapping', $html);
        self::assertStringNotContainsString('E-book', $html);
    }

    public function testADraftBeingPreparedAlreadyHoldsItsLines(): void
    {
        $order = $this->partlyShippedOrder();
        $order['orderShipments'][1]['status'] = 'draft';

        $html = $this->render($order);

        self::assertStringNotContainsString('Rain jacket', $html);
        self::assertMatchesRegularExpression('#1 &times;.*Mesh shoes#s', $html);
    }

    public function testNothingIsOwedOnceEveryParcelLeft(): void
    {
        $order = $this->partlyShippedOrder();
        $order['orderShipments'][0]['orderShipmentItems'][0]['quantity'] = 3;
        $order['orderShipments'][1]['status'] = 'shipped';

        self::assertSame('', trim($this->render($order)));
    }

    public function testAnOrderNoParcelLeftYetSaysNothing(): void
    {
        $order = $this->partlyShippedOrder();
        $order['orderShipments'] = [];

        self::assertSame('', trim($this->render($order)), 'everything is owed, which the order status already says');
    }
}
