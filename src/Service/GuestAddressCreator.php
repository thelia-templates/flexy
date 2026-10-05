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
use Thelia\Domain\Legal\CompanyIdentifier;
use Thelia\Model\AddressQuery;
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
        $existing = $this->addressAlreadyWritten($customer, $address, $titleId, $firstname, $lastname);

        if (null !== $existing) {
            return $existing;
        }

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

    /**
     * The very same address, already on this record.
     *
     * A buyer ordering again from the address they ordered from before types the same
     * thing again, and the record is kept from one order to the next: without this, every
     * order leaves another copy of it, and the day they open an account they find their
     * own address several times over. Every field the form asks for has to match — one
     * different character is a different address, not a correction.
     *
     * @param array<string, mixed> $address
     */
    private function addressAlreadyWritten(
        Customer $customer,
        array $address,
        int $titleId,
        string $firstname,
        string $lastname,
    ): ?int {
        // Compared in PHP rather than filtered in SQL: an unfilled field reaches the row
        // as NULL and the form as an empty string, and the two have to read as the same
        // "nothing" — which is exactly what a SQL equality does not do.
        $typed = [
            $titleId,
            $firstname,
            $lastname,
            self::text($address, 'company'),
            (string) CompanyIdentifier::forCompany(self::text($address, 'company'), CompanyIdentifier::normalizeSiret(self::text($address, 'siret'))),
            (string) CompanyIdentifier::forCompany(self::text($address, 'company'), CompanyIdentifier::normalizeVatNumber(self::text($address, 'vat_number'))),
            self::text($address, 'address1'),
            self::text($address, 'address2'),
            self::text($address, 'zipcode'),
            self::text($address, 'city'),
            (int) ($address['country'] ?? 0),
            self::text($address, 'state'),
            self::text($address, 'phone'),
            self::text($address, 'cellphone'),
        ];

        foreach (AddressQuery::create()->filterByCustomerId($customer->getId())->find() as $candidate) {
            $written = [
                (int) $candidate->getTitleId(),
                (string) $candidate->getFirstname(),
                (string) $candidate->getLastname(),
                (string) $candidate->getCompany(),
                (string) $candidate->getSiret(),
                (string) $candidate->getVatNumber(),
                (string) $candidate->getAddress1(),
                (string) $candidate->getAddress2(),
                (string) $candidate->getZipcode(),
                (string) $candidate->getCity(),
                (int) $candidate->getCountryId(),
                (string) $candidate->getStateId(),
                (string) $candidate->getPhone(),
                (string) $candidate->getCellphone(),
            ];

            if ($written === $typed) {
                return (int) $candidate->getId();
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $address
     */
    private static function text(array $address, string $field): string
    {
        return (string) ($address[$field] ?? '');
    }
}
