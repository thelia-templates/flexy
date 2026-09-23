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
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
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

    public function mount(int $addressId, bool $inOrder = false, bool $withModal = false): void
    {
        $this->addressId = $addressId;
        $this->inOrder = $inOrder;
        $this->withModal = $withModal;

        $addressQuery = $inOrder ? OrderAddressQuery::create() : AddressQuery::create();

        // joinWith, not useCountryQuery: the join alone leaves the country unhydrated and
        // the template then had to fetch it over the API, once per card.
        $address = $addressQuery
            ->joinWithCountry()
            ->findOneById($addressId)
        ;

        $this->address = $address?->toArray(TableMap::TYPE_CAMELNAME);
        $this->countryIsoAlpha3 = $address?->getCountry()?->getIsoalpha3();

        if (null !== $this->address && ($address instanceof Address || $address instanceof OrderAddress)) {
            $this->address['vatVerificationValid'] = $address instanceof Address
                ? $address->getVatVerificationValid()
                : null !== $address->getVatVerifiedAt();
        }
    }
}
