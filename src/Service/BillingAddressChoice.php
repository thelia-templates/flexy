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

namespace FlexyBundle\Service;

/**
 * Which address the payment step opens on when the cart names none.
 *
 * The buyer is billed where the order ships — the answer a checkout of two addresses is
 * right about nearly every time — and, for a cart with nothing to ship and therefore no
 * delivery step, on the address their account calls the default one. Failing both, the
 * first of the book, which is the one the address card then shows: the card is what the
 * buyer changes the choice from, so nothing here is decided out of their sight.
 *
 * A decision and nothing else, so that it can be read and tested on its own: what it
 * answers is written to the cart by the component that asks.
 */
final readonly class BillingAddressChoice
{
    /**
     * @param array<int, array<string, mixed>> $addressBook the addresses this checkout may use,
     *                                                      as GuestCheckoutGate::visibleAddresses() answers them
     * @param int|null                         $shipsTo     the address the cart delivers to, if any
     *
     * @return int|null the address to bill, or null when there is nothing to choose from
     */
    public static function from(array $addressBook, ?int $shipsTo): ?int
    {
        if ([] === $addressBook) {
            return null;
        }

        $visibleIds = array_map(
            static fn (array $address): int => (int) $address['id'],
            array_values($addressBook),
        );

        // An address the cart names but this checkout cannot see belongs to another buyer
        // of the same shared guest row: it is not this one's to bill.
        if (null !== $shipsTo && \in_array($shipsTo, $visibleIds, true)) {
            return $shipsTo;
        }

        foreach ($addressBook as $address) {
            if (!empty($address['isDefault'])) {
                return (int) $address['id'];
            }
        }

        return $visibleIds[0];
    }
}
