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

use Propel\Runtime\Exception\PropelException;
use Thelia\Domain\Shipping\ShippingFacade;
use Thelia\Model\Cart;

/**
 * The delivery address a guest's cart carries when the checkout puts one on it by itself: a
 * pickup, a virtual order.
 *
 * The core falls back on the default address of the customer row there. A guest row is
 * reused by whoever types the same e-mail address, and each identification makes its own
 * address the default one: for a guest, that default may be another buyer's. The cart of a
 * guest keeps the address it has when it is one of theirs, takes one of their identification
 * otherwise, and is left as it is when they have none.
 */
final readonly class GuestDeliveryAddress
{
    public function __construct(
        private GuestCheckoutGate $guestCheckoutGate,
        private ShippingFacade $shippingFacade,
    ) {
    }

    /**
     * A copy the cart has is kept when its source is an address of this identification, and
     * when it has no source: such a copy was written for this cart alone, by this buyer, and
     * nothing of the guest row is in it.
     *
     * @return bool whether the cart's copy of its delivery address changed; false for a cart
     *              that is not a guest's
     *
     * @throws PropelException
     */
    public function keepAnAddressOfTheGuest(Cart $cart): bool
    {
        if (true !== $cart->getCustomer()?->isGuest()) {
            return false;
        }

        $copy = $cart->getCartAddressRelatedByAddressDeliveryId();

        if (null !== $copy) {
            $sourceId = $copy->getAddressId();

            if (null === $sourceId || $this->guestCheckoutGate->isVisible($sourceId)) {
                return false;
            }
        }

        $addressId = $this->guestCheckoutGate->ownAddressId();

        if (null === $addressId) {
            return false;
        }

        $this->shippingFacade->chooseDeliveryAddress($cart, $addressId);

        return true;
    }

    /**
     * What the checkout calls before the core settles the delivery of a virtual cart, which
     * would otherwise copy the default address of the guest row onto it.
     *
     * @throws PropelException
     */
    public function beforeAVirtualDelivery(Cart $cart): void
    {
        if (null !== $cart->getDeliveryModuleId() || !$cart->isVirtual()) {
            return;
        }

        $this->keepAnAddressOfTheGuest($cart);
    }
}
