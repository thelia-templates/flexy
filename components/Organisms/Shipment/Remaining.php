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
 * What an order shipped in several still owes the customer: each line that physically
 * ships, with the quantity no parcel has taken yet. Read off the order payload of the
 * front resource, the same way the core counts it: a draft holds its lines as firmly
 * as a parcel that left, a cancelled one holds nothing, a service line (gift wrapping)
 * and a virtual product never ship.
 *
 * Says nothing until a first parcel exists: before that, everything is owed, and the
 * status of the order already says so.
 */
#[AsTwigComponent]
class Remaining
{
    /** Below this, two FLOAT quantities are the same, as in the core. */
    private const float TOLERANCE = 0.0001;

    private const string STATUS_CANCELLED = 'cancelled';
    private const string LINE_TYPE_SERVICE = 'service';

    /** @var array<string, mixed> */
    public array $order = [];

    public function isShown(): bool
    {
        return $this->hasParcel() && [] !== $this->getLines();
    }

    /**
     * @return list<array{title: string, ref: string, quantity: float}>
     */
    public function getLines(): array
    {
        $held = $this->heldByLine();
        $lines = [];

        foreach ($this->order['orderProducts'] ?? [] as $orderProduct) {
            if (!\is_array($orderProduct) || !$this->ships($orderProduct)) {
                continue;
            }

            $left = (float) ($orderProduct['quantity'] ?? 0) - ($held[(int) ($orderProduct['id'] ?? 0)] ?? 0.0);

            if ($left <= self::TOLERANCE) {
                continue;
            }

            $lines[] = [
                'title' => (string) ($orderProduct['title'] ?? ''),
                'ref' => (string) ($orderProduct['productRef'] ?? ''),
                'quantity' => $left,
            ];
        }

        return $lines;
    }

    private function hasParcel(): bool
    {
        foreach ($this->order['orderShipments'] ?? [] as $shipment) {
            if (\is_array($shipment) && self::STATUS_CANCELLED !== ($shipment['status'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, float>
     */
    private function heldByLine(): array
    {
        $held = [];

        foreach ($this->order['orderShipments'] ?? [] as $shipment) {
            if (!\is_array($shipment) || self::STATUS_CANCELLED === ($shipment['status'] ?? null)) {
                continue;
            }

            foreach ($shipment['orderShipmentItems'] ?? [] as $item) {
                if (!\is_array($item)) {
                    continue;
                }

                $lineId = (int) ($item['orderProduct']['id'] ?? 0);
                $held[$lineId] = ($held[$lineId] ?? 0.0) + (float) ($item['quantity'] ?? 0);
            }
        }

        return $held;
    }

    /**
     * @param array<string, mixed> $orderProduct
     */
    private function ships(array $orderProduct): bool
    {
        return !($orderProduct['virtual'] ?? false)
            && self::LINE_TYPE_SERVICE !== ($orderProduct['lineType'] ?? null);
    }
}
