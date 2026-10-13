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

namespace FlexyBundle\Components\Organisms\Shipment;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * One parcel of an order shipped in several, as the customer reads it on the order
 * page: its rank, its lines, whether it left and when, its tracking number with the
 * carrier page, and its own delivery note.
 *
 * Fed with a shipment of the order read (`orderShipments` of the front order
 * resource). The carrier page comes from the core (`trackingUrl`), already checked to
 * be a web address, so nothing here builds or trusts a link of its own.
 */
#[AsTwigComponent]
class Base
{
    public const string STATUS_DRAFT = 'draft';
    public const string STATUS_SHIPPED = 'shipped';

    /** @var array<string, mixed> */
    public array $shipment = [];

    /** The rank of the parcel among the parcels of the order, from 1. */
    public int $position = 1;

    public int $total = 1;

    /** The address of this parcel's delivery note, or null when none is offered. */
    public ?string $deliveryNoteUrl = null;

    public function isShipped(): bool
    {
        return self::STATUS_SHIPPED === ($this->shipment['status'] ?? null);
    }

    public function getReference(): string
    {
        return (string) ($this->shipment['ref'] ?? '');
    }

    public function getTrackingNumber(): ?string
    {
        $number = trim((string) ($this->shipment['deliveryRef'] ?? ''));

        return '' === $number ? null : $number;
    }

    public function getTrackingUrl(): ?string
    {
        $url = $this->shipment['trackingUrl'] ?? null;

        return \is_string($url) && '' !== $url && null !== $this->getTrackingNumber() ? $url : null;
    }

    public function getCarrier(): ?string
    {
        $carrier = trim((string) ($this->shipment['deliveryModuleTitle'] ?? ''));

        return '' === $carrier ? null : $carrier;
    }

    public function getShippedAt(): ?string
    {
        $shippedAt = $this->shipment['shippedAt'] ?? null;

        return \is_string($shippedAt) && '' !== $shippedAt ? $shippedAt : null;
    }

    /**
     * The lines the parcel carries, each with the quantity it carries: what the
     * customer checks against the box.
     *
     * @return list<array{title: string, ref: string, quantity: float}>
     */
    public function getLines(): array
    {
        $lines = [];

        foreach ($this->shipment['orderShipmentItems'] ?? [] as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $orderProduct = \is_array($item['orderProduct'] ?? null) ? $item['orderProduct'] : [];
            $lines[] = [
                'title' => (string) ($orderProduct['title'] ?? ''),
                'ref' => (string) ($orderProduct['productRef'] ?? ''),
                'quantity' => (float) ($item['quantity'] ?? 0),
            ];
        }

        return $lines;
    }
}
