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

/** One parcel of an order shipped in several, as the order page shows it. */
final class ShipmentRenderTest extends KernelTestCase
{
    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender('Organisms:Shipment:Base', $props);
    }

    /**
     * @return array<string, mixed>
     */
    private function shippedParcel(): array
    {
        return [
            'ref' => 'ORD42-1',
            'status' => 'shipped',
            'deliveryRef' => '6A12',
            'deliveryModuleTitle' => 'Carrier Co',
            'trackingUrl' => 'https://carrier.example/track?parcel=6A12&lang=fr',
            'shippedAt' => '2026-10-13T10:00:00+00:00',
            'orderShipmentItems' => [
                ['quantity' => 2, 'orderProduct' => ['title' => 'Mesh shoes', 'productRef' => 'SHOE-42']],
                ['quantity' => 1, 'orderProduct' => ['title' => 'Socks', 'productRef' => 'SOCK-7']],
            ],
        ];
    }

    public function testAParcelThatLeftShowsItsRankLinesNumberAndCarrierPage(): void
    {
        $html = $this->render(['shipment' => $this->shippedParcel(), 'position' => 1, 'total' => 2, 'deliveryNoteUrl' => '/account/order/pdf/delivery/42/1']);

        self::assertStringContainsString('Shipment 1 of 2', $html);
        self::assertStringContainsString('ORD42-1', $html);
        self::assertStringContainsString('6A12', $html);
        self::assertStringContainsString('Carrier Co', $html);
        self::assertMatchesRegularExpression('#2 &times;.*Mesh shoes#s', $html);
        self::assertMatchesRegularExpression('#1 &times;.*Socks#s', $html);

        preg_match('/<a[^>]*data-testid="shipment-tracking-link"[^>]*>/', $html, $link);
        self::assertNotEmpty($link, 'the parcel links its number to the carrier page');
        self::assertStringContainsString('href="https://carrier.example/track?parcel=6A12&amp;lang=fr"', $link[0]);
        self::assertStringContainsString('target="_blank"', $link[0]);
        self::assertMatchesRegularExpression('/rel="[^"]*noopener[^"]*"/', $link[0]);

        preg_match('/<a[^>]*data-testid="shipment-delivery-note-link"[^>]*>/', $html, $note);
        self::assertNotEmpty($note, 'the parcel offers its delivery note');
        self::assertStringContainsString('href="/account/order/pdf/delivery/42/1"', $note[0]);
    }

    public function testAParcelBeingPreparedHasNoLinkAndSaysSo(): void
    {
        $parcel = $this->shippedParcel();
        $parcel['status'] = 'draft';
        $parcel['deliveryRef'] = null;
        $parcel['trackingUrl'] = null;
        $parcel['shippedAt'] = null;

        $html = $this->render(['shipment' => $parcel, 'position' => 2, 'total' => 2]);

        self::assertStringContainsString('Being prepared', $html);
        self::assertStringNotContainsString('shipment-tracking-link', $html);
        self::assertStringNotContainsString('shipment-delivery-note-link', $html);
        self::assertStringNotContainsString('carrier.example', $html);
    }

    public function testACarrierPageWithoutANumberIsNotLinked(): void
    {
        $parcel = $this->shippedParcel();
        $parcel['deliveryRef'] = '';

        $html = $this->render(['shipment' => $parcel, 'position' => 1, 'total' => 1]);

        self::assertStringNotContainsString('shipment-tracking-link', $html);
    }
}
