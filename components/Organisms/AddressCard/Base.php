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

namespace FlexyBundle\Components\Organisms\AddressCard;

use Propel\Runtime\Map\TableMap;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Domain\Addressing\Service\AddressAccess;
use Thelia\Domain\Customer\CustomerFacade;
use Thelia\Model\Address;
use Thelia\Model\AddressQuery;
use Thelia\Model\OrderAddress;
use Thelia\Model\OrderAddressQuery;

#[AsTwigComponent]
class Base
{
    public int $addressId;
    public ?array $address = null;
    public ?string $countryIsoAlpha3 = null;
    public bool $withModal = false;
    public bool $inOrder = false;
    public bool $checked = false;

    /** Whether the signed-in customer may change or delete the address: theirs, or one of their company they manage. */
    public bool $canEdit = false;

    /** Whether the address is one the customer created, the only kind that can be their default. */
    public bool $ownedByCustomer = false;

    /** Whether the address is shared with a company. */
    public bool $shared = false;

    private ?AddressAccess $addressAccess = null;

    private ?CustomerFacade $customerFacade = null;

    // Setters: a card of the checkout extends this one with a constructor of its own.
    #[Required]
    public function setAddressAccess(AddressAccess $addressAccess, CustomerFacade $customerFacade): void
    {
        $this->addressAccess = $addressAccess;
        $this->customerFacade = $customerFacade;
    }

    public function mount(int $addressId, bool $inOrder = false, bool $withModal = false): void
    {
        $this->addressId = $addressId;
        $this->inOrder = $inOrder;
        $this->withModal = $withModal;

        // An address of the address book is read through what the customer may use: their own, and
        // the ones shared with their company. A card never opens an address by its number alone.
        $address = $inOrder ? $this->orderAddress($addressId) : $this->bookAddress($addressId);

        $this->address = $address?->toArray(TableMap::TYPE_CAMELNAME);
        $this->countryIsoAlpha3 = $address?->getCountry()?->getIsoalpha3();

        if (null !== $this->address && ($address instanceof Address || $address instanceof OrderAddress)) {
            $this->address['vatVerificationValid'] = $address instanceof Address
                ? $address->getVatVerificationValid()
                : null !== $address->getVatVerifiedAt();
        }
    }

    private function orderAddress(int $addressId): ?OrderAddress
    {
        // joinWithCountry, not useCountryQuery: the join alone leaves the country unhydrated and
        // the template then had to fetch it over the API, once per card.
        return OrderAddressQuery::create()->joinWithCountry()->findOneById($addressId);
    }

    private function bookAddress(int $addressId): ?Address
    {
        $customer = $this->customerFacade?->getCurrentCustomer();

        if (null === $customer || null === $this->addressAccess) {
            return null;
        }

        $address = $this->addressAccess->restrict(AddressQuery::create(), $customer)->joinWithCountry()->findOneById($addressId);

        if (null !== $address) {
            $this->canEdit = $this->addressAccess->canEdit($customer, $address);
            $this->ownedByCustomer = (int) $address->getCustomerId() === (int) $customer->getId();
            $this->shared = null !== $address->getCompanyId();
        }

        return $address;
    }
}
