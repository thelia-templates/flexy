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

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Map\TableMap;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Cart\Service\CartContext;
use Thelia\Domain\Checkout\Service\GuestCheckoutPolicy;
use Thelia\Model\Cart;
use Thelia\Model\Customer;
use Thelia\Model\CustomerQuery;

/**
 * Everything the theme needs to know about someone going through the checkout without
 * an account: whether the shop offers it for what is in the cart, and how such a
 * visitor is put in the session.
 *
 * The session customer is set the same way for a guest as for a signed-in one, because
 * the checkout builds the order from it. What tells them apart is `customer.is_guest`,
 * which the core reads through SecurityContext::hasAuthenticatedCustomerUser() — every
 * account page asks it, and this checkout deliberately does not.
 */
final readonly class GuestCheckoutGate
{
    /**
     * The addresses written for the guest of *this* identification.
     *
     * One guest row is shared by everyone who ever ordered on an address — nobody proved
     * they own it, so the checkout reuses the row rather than opening a second one — and
     * the addresses of every previous buyer hang off it. Without this, the delivery step
     * of the second visitor lists the first one's address book.
     */
    private const IDENTIFICATION_ADDRESS_IDS_KEY = 'flexy.guest_address_ids';

    public function __construct(
        private SecurityContext $securityContext,
        private RequestStack $requestStack,
        private GuestCheckoutPolicy $guestCheckoutPolicy,
        private CartFacade $cartFacade,
        private CartContext $cartContext,
        private GuestOrderTracking $guestOrderTracking,
        private PlacedOrderMemory $placedOrderMemory,
    ) {
    }

    /**
     * Whether the checkout may be entered at all: a signed-in customer, or a guest who
     * already identified themselves earlier in this session.
     */
    public function mayEnterCheckout(): bool
    {
        return $this->securityContext->hasCustomerUser();
    }

    /**
     * Whether the shop lets the cart in hand be ordered without an account.
     *
     * A shop with the setting off answers false here whatever the cart holds, which is
     * what keeps the checkout of such a shop exactly as it was.
     *
     * @throws PropelException
     */
    public function isOfferedForCurrentCart(): bool
    {
        if (!$this->guestCheckoutPolicy->isGuestCheckoutEnabled()) {
            return false;
        }

        $cart = $this->cartFacade->getCartFromSession();

        if (!$cart instanceof Cart) {
            return false;
        }

        return $this->guestCheckoutPolicy->isGuestCheckoutAllowedForCart($cart);
    }

    /**
     * The page a visitor with no identity is sent to when they reach a checkout step.
     *
     * @throws PropelException
     */
    public function entryPointRoute(): string
    {
        return $this->isOfferedForCurrentCart() ? 'checkout_identify' : 'customer_login';
    }

    /**
     * The guest row that already carries a password for that address, if there is one.
     *
     * Such a row is a buyer who chose a password and never answered the activation code:
     * it is still a guest, so the login provider does not see it and the login form can
     * only ever answer "wrong email or password". The activation page is the one way in
     * for them, and this is what points them at it.
     *
     * @throws PropelException
     */
    public function accountAwaitingActivationFor(string $email): ?Customer
    {
        $guestRow = CustomerQuery::create()
            ->filterByEmail($email)
            ->filterByIsGuest(1)
            ->filterByAnonymizedAt(null, Criteria::ISNULL)
            ->orderById(Criteria::DESC)
            ->findOne()
        ;

        if (!$guestRow instanceof Customer || '' === (string) $guestRow->getPassword()) {
            return null;
        }

        return $guestRow;
    }

    /**
     * Put the guest in the session, and hand them the cart they filled as a visitor.
     *
     * Binding the cart is what CUSTOMER_LOGIN does for someone who signs in. A guest
     * never raises that event — they hold no credentials and nothing about them should
     * be treated as a login — so the same binding is done here: without it the order is
     * built from a cart that belongs to nobody and ends up with no customer at all.
     *
     * The addresses come in with the guest rather than being remembered afterwards: the
     * session must never hold a guest together with the address ids of an earlier one.
     *
     * @param list<int> $identificationAddressIds the addresses written for this identification
     *
     * @throws PropelException
     */
    public function signIn(Customer $guest, array $identificationAddressIds = []): void
    {
        // The model comes out of the creation event still holding the event dispatcher,
        // and the session is serialized: left in place it takes the whole container down
        // with it, and the session comes back without the customer it was given. The
        // core login does exactly this before putting a customer in the session.
        if (method_exists($guest, 'clearDispatcher')) {
            $guest->clearDispatcher();
        }

        $this->securityContext->setCustomerUser($guest);
        $this->session()?->set(self::IDENTIFICATION_ADDRESS_IDS_KEY, array_values($identificationAddressIds));

        $cart = $this->cartFacade->getOrCreateFromSession();
        $cart->setCustomerId($guest->getId())->save();
        $this->cartContext->addCartSession($cart);
    }

    /**
     * The address book of the checkout, as the session in hand is entitled to see it.
     *
     * A signed-in customer sees their own, whole. A guest sees only what they typed on
     * the identification page: the row they were given may have carried other buyers'
     * addresses long before they got to it.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PropelException
     */
    public function visibleAddresses(): array
    {
        $customer = $this->securityContext->getCustomerUser();

        if (!$customer instanceof Customer) {
            return [];
        }

        $addresses = $customer->getAddresses()->toArray(null, false, TableMap::TYPE_CAMELNAME);

        if (!$customer->isGuest()) {
            return $addresses;
        }

        $ownAddressIds = $this->identificationAddressIds();

        return array_values(array_filter(
            $addresses,
            static fn (array $address): bool => \in_array((int) $address['id'], $ownAddressIds, true),
        ));
    }

    /**
     * Retire whatever this session was carrying on behalf of someone with no account.
     *
     * Called when a real customer takes the session over. The tracking token is the one
     * that matters: it opens an order, and whoever signs in is not necessarily the person
     * who placed it. The other end — the session being given up — is
     * {@see \FlexyBundle\EventListener\GuestOrderPlacedSubscriber}, which hangs off
     * CUSTOMER_LOGOUT and so covers every way out, not only this theme's own.
     */
    public function markAsAuthenticated(): void
    {
        $this->guestOrderTracking->forgetPlacedOrder();
        $this->placedOrderMemory->forget();
        $this->session()?->remove(self::IDENTIFICATION_ADDRESS_IDS_KEY);
    }

    /**
     * @return list<int>
     */
    private function identificationAddressIds(): array
    {
        $addressIds = $this->session()?->get(self::IDENTIFICATION_ADDRESS_IDS_KEY);

        if (!\is_array($addressIds)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $addressId): int => (int) $addressId,
            array_filter($addressIds, static fn (mixed $addressId): bool => is_numeric($addressId)),
        ));
    }

    /**
     * The session of the request being served.
     *
     * Never the container's own Session instance: that one is shared, and a process
     * serving more than one request would go on writing into the session of the first.
     */
    private function session(): ?Session
    {
        $request = $this->requestStack->getMainRequest();
        $session = $request?->hasSession() ? $request->getSession() : null;

        return $session instanceof Session ? $session : null;
    }
}
