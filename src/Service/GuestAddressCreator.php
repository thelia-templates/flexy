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

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Address\AddressCreateOrUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Customer;

/**
 * Writes the addresses a guest typed on the identification page.
 *
 * AddressService takes a Symfony form whose fields carry the whole address, which the
 * guest form does not: it asks for the buyer once and for one or two addresses under
 * it. The address rows are therefore built from plain values here, through the same
 * ADDRESS_CREATE event, so that whatever a module hangs off address creation still runs.
 */
final readonly class GuestAddressCreator
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @param array<string, mixed> $address as the guest checkout form submits it
     *
     * @return int the id of the address that was written
     */
    public function create(
        Customer $customer,
        array $address,
        string $label,
        int $titleId,
        string $firstname,
        string $lastname,
        bool $isDefault,
    ): int {
        $event = new AddressCreateOrUpdateEvent(
            $label,
            $titleId,
            $firstname,
            $lastname,
            (string) ($address['address1'] ?? ''),
            (string) ($address['address2'] ?? ''),
            '',
            (string) ($address['zipcode'] ?? ''),
            (string) ($address['city'] ?? ''),
            (int) ($address['country'] ?? 0),
            (string) ($address['cellphone'] ?? ''),
            (string) ($address['phone'] ?? ''),
            $address['company'] ?? null,
            $isDefault,
            isset($address['state']) && '' !== $address['state'] ? (int) $address['state'] : null,
            $address['siret'] ?? null,
            $address['vat_number'] ?? null,
        );

        $event->setCustomer($customer);

        $this->eventDispatcher->dispatch($event, TheliaEvents::ADDRESS_CREATE);

        // The id is what the checkout keeps: the guest row it hangs off may be shared
        // with buyers who came before, so the addresses of this identification are the
        // only ones this visitor is entitled to see.
        return (int) $event->getAddress()->getId();
    }
}
