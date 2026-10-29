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

use FlexyBundle\Components\Forms\OrderReturn\Request as ReturnRequest;
use FlexyBundle\Components\Layouts\OrderProducts\Base as OrderProducts;
use FlexyBundle\Components\Organisms\OrderCard\Base as OrderCard;
use FlexyBundle\Components\Organisms\Shipment\Base as Shipment;
use FlexyBundle\Components\Organisms\Shipment\Remaining;
use FlexyBundle\Components\Organisms\Summary\Order as SummaryOrder;
use FlexyBundle\Service\OrderProductResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Thelia\Api\Service\DataAccess\DataAccessService;

/**
 * An order that holds a bundle: its components are listed under the line of the pack and are not articles of their
 * own, the parcels and the return form follow the predicates of the core (the pack is not shipped, its components
 * are; a component is not returned on its own).
 */
final class BundleOrderLinesTest extends KernelTestCase
{
    public function testTheComponentsHangUnderTheLineOfTheirBundleAndHaveNoCardOfTheirOwn(): void
    {
        $component = new OrderProducts($this->resolver());
        $component->mount($this->orderProducts());

        self::assertSame([10, 13], array_column(array_column($component->lines, 'orderProduct'), 'id'), 'one card for the pack, one for the plain product');
        // Two bundles bought, each holds 1 bike and 2 helmets: the order stores 2 and 4.
        self::assertSame([['title' => 'Mountain bike', 'quantity' => '1'], ['title' => 'Helmet', 'quantity' => '2']], $component->lines[0]['included']);
        self::assertSame([], $component->lines[1]['included']);
    }

    public function testAServiceLineIsStillNotACard(): void
    {
        $component = new OrderProducts($this->resolver());
        $component->mount([['id' => 20, 'title' => 'Gift wrapping', 'lineType' => 'service', 'quantity' => 1], ['id' => 21, 'title' => 'Mug', 'lineType' => 'product', 'quantity' => 1]]);

        self::assertSame([21], array_column(array_column($component->lines, 'orderProduct'), 'id'));
    }

    public function testTheSummaryDoesNotCountTheComponentsAsArticles(): void
    {
        $summary = new SummaryOrder();
        $summary->order = ['orderProducts' => $this->orderProducts()];

        // The pack counts for the 2 bought and the plain product for 1: 4 and 8 more would be the components.
        self::assertSame(3, $summary->getItemCount());
    }

    public function testTheOrderCardCountsAndShowsTheBundleOnce(): void
    {
        $dataAccess = $this->createStub(DataAccessService::class);
        $dataAccess->method('resources')->willReturn(['orderProducts' => $this->orderProducts()]);
        $card = new OrderCard($dataAccess, $this->resolver());
        $card->mount(1);

        self::assertSame(2, $card->getItemCount(), 'the pack and the plain product');
        self::assertCount(2, $card->getThumbnails());
        self::assertSame(['Bike pack', 'Mug'], array_column($card->getThumbnails(), 'title'));
    }

    public function testTheLineOfTheBundleIsNotOwedAsAParcelButItsComponentsAre(): void
    {
        $remaining = new Remaining();
        $remaining->order = [
            'orderProducts' => $this->orderProducts(),
            'orderShipments' => [['status' => 'shipped', 'orderShipmentItems' => [['quantity' => 1, 'orderProduct' => ['id' => 11]]]]],
        ];

        // The bike is 2 ordered and 1 sent; the helmets 4 ordered, none sent; the mug 1 ordered. The pack ships nothing.
        self::assertSame(['Mountain bike', 'Helmet', 'Mug'], array_column($remaining->getLines(), 'title'));
        self::assertSame([1.0, 4.0, 1.0], array_column($remaining->getLines(), 'quantity'));
    }

    public function testAParcelNeverListsTheLineOfABundle(): void
    {
        $shipment = new Shipment();
        $shipment->shipment = ['orderShipmentItems' => [
            ['quantity' => 1, 'orderProduct' => ['title' => 'Bike pack', 'productRef' => 'PACK', 'lineType' => 'bundle']],
            ['quantity' => 1, 'orderProduct' => ['title' => 'Mountain bike', 'productRef' => 'BIKE', 'lineType' => 'bundle_component']],
            ['quantity' => 1, 'orderProduct' => ['title' => 'Mug', 'productRef' => 'MUG']],
        ]];

        self::assertSame(['Mountain bike', 'Mug'], array_column($shipment->getLines(), 'title'));
    }

    public function testTheReturnFormNeverOffersAComponentOnItsOwn(): void
    {
        $dataAccess = $this->createStub(DataAccessService::class);
        $dataAccess->method('resources')->willReturnCallback(fn (string $path): mixed => str_contains($path, 'account/orders/') ? ['orderProducts' => $this->orderProducts()] : []);
        $form = new ReturnRequest($dataAccess, $this->resolver());

        // Even a list that names the components as returnable (the core does not, the form does not trust it either).
        $form->mount(1, [10 => 2.0, 11 => 2.0, 12 => 4.0, 13 => 1.0]);

        self::assertSame([10, 13], array_column(array_column($form->lines, 'orderProduct'), 'id'));
    }

    private function resolver(): OrderProductResolver
    {
        $dataAccess = $this->createStub(DataAccessService::class);
        $dataAccess->method('resources')->willReturn([]);

        return new OrderProductResolver($dataAccess);
    }

    /**
     * Two bundles bought (1 bike and 2 helmets each) and one mug.
     *
     * @return list<array<string, mixed>>
     */
    private function orderProducts(): array
    {
        return [
            ['id' => 10, 'title' => 'Bike pack', 'productRef' => 'PACK', 'quantity' => 2, 'lineType' => 'bundle', 'parent' => null],
            ['id' => 11, 'title' => 'Mountain bike', 'productRef' => 'BIKE', 'quantity' => 2, 'lineType' => 'bundle_component', 'parent' => 10],
            ['id' => 12, 'title' => 'Helmet', 'productRef' => 'HELMET', 'quantity' => 4, 'lineType' => 'bundle_component', 'parent' => 10],
            ['id' => 13, 'title' => 'Mug', 'productRef' => 'MUG', 'quantity' => 1, 'lineType' => 'product', 'parent' => null],
        ];
    }
}
