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

use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Addressing\Service\AddressAccess;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Cart\Service\CartAddressService;

/**
 * The cart keeps its own copy of the delivery address, taken when the address was chosen,
 * and the order leaves for that copy: an edit of that very address has to reach it.
 *
 * Another street, name or phone is copied over at once, and the carrier stays. Another
 * country, state, postal code or city is a new place: a carrier may price by city as well
 * as by postal code (GeolocatedDelivery does), so its carriers and their postage are asked
 * again, which only choosing the address again on the delivery step does.
 *
 * Only an address the checkout shows the buyer is followed: the id comes with a request,
 * the customer row of a guest carries the addresses of every earlier buyer of that e-mail
 * address, and a buyer of a company reads the ones of their company beside their own.
 */
final readonly class DeliveryAddressCopy
{
    public function __construct(
        private CartFacade $cartFacade,
        private CartAddressService $cartAddressService,
        private GuestCheckoutGate $guestCheckoutGate,
        private AddressAccess $addressAccess,
        private SecurityContext $securityContext,
    ) {
    }

    /**
     * Copies the edited delivery address over the cart's copy, unless it moved.
     *
     * @return bool whether the address moved to another place and has to be chosen again;
     *              false, with nothing touched, for an address the buyer is not shown
     */
    public function followUnlessMoved(int $addressId): bool
    {
        if (!$this->guestCheckoutGate->isVisible($addressId)) {
            return false;
        }

        $copy = $this->cartFacade->getOrCreateFromSession()->getCartAddressRelatedByAddressDeliveryId();
        // Read through what the customer may use, not by its number: the gate above answers for the
        // list the buyer is shown, and an address they may not use is none of this method's business.
        $address = $this->addressAccess->find($this->securityContext->getCustomerUser(), $addressId);

        if (null === $copy || null === $address) {
            return false;
        }

        if ($copy->getCountryId() !== $address->getCountryId()
            || $copy->getStateId() !== $address->getStateId()
            || (string) $copy->getZipcode() !== (string) $address->getZipcode()
            || (string) $copy->getCity() !== (string) $address->getCity()) {
            return true;
        }

        // Saved only when a copied column changed: Propel writes nothing otherwise.
        $this->cartAddressService->getOrCreateCartAddressFromAddress($address, $copy);

        return false;
    }
}
