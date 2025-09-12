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

namespace FlexyBundle\UiComponents\AddressCard;

use Propel\Runtime\Map\TableMap;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Model\AddressQuery;
use Thelia\Model\OrderAddressQuery;

#[AsTwigComponent(name: 'Flexy:AddressCard', template: '@UiComponents/AddressCard/AddressCard.html.twig')]
class AddressCard
{
    public int $addressId;
    public ?array $address;
    public bool $withModal;
    public bool $inOrder = false;

    public function mount(int $addressId, ?bool $inOrder = false, ?bool $withModal = false): void
    {
        $addressQuery = $inOrder ? OrderAddressQuery::create() : AddressQuery::create();

        $this->address = $addressQuery->useCountryQuery()
            ->endUse()
            ->findOneById($addressId)
            ->toArray(TableMap::TYPE_CAMELNAME);

        if ($withModal) {
            $this->withModal = $withModal;
        }

        if ($inOrder) {
            $this->inOrder = $inOrder;
        }

        $this->addressId = $addressId;
    }
}
