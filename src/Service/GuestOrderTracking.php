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

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Thelia\Domain\Order\Service\GuestOrderAccessLimiter;
use Thelia\Domain\Order\Service\GuestOrderAccessService;
use Thelia\Model\Order;

/**
 * The only way back to an order placed without an account.
 *
 * The link is the whole of the authentication, so every read goes through the rate
 * limiter first and answers the same way for a token that names no order, one whose
 * signature does not match and one that has expired.
 */
final readonly class GuestOrderTracking
{
    /**
     * Where the token of the order just placed is kept, so the confirmation page can
     * offer the link and the account creation.
     *
     * The core retires the guest from the session as soon as the order exists, which is
     * what should happen — the next person on this browser must not inherit an identity
     * nobody signed into. This token is not that identity: it opens one order and
     * nothing else, and it is what the buyer receives by email anyway.
     */
    private const PLACED_ORDER_TOKEN_KEY = 'flexy.guest_order_token';

    public function __construct(
        private GuestOrderAccessService $guestOrderAccessService,
        private GuestOrderAccessLimiter $guestOrderAccessLimiter,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * The order a tracking token names, or null when it may not be read.
     *
     * The limiter is consumed before anything is checked, so a caller spends the same
     * budget whether or not the token turns out to be valid.
     */
    public function findOrder(string $token): ?Order
    {
        if (!$this->guestOrderAccessLimiter->allows($token)) {
            return null;
        }

        return $this->guestOrderAccessService->findOrderForToken($token);
    }

    public function rememberPlacedOrder(Order $order): void
    {
        $this->session()?->set(
            self::PLACED_ORDER_TOKEN_KEY,
            $this->guestOrderAccessService->createToken($order),
        );
    }

    public function tokenOfPlacedOrder(): ?string
    {
        $token = $this->session()?->get(self::PLACED_ORDER_TOKEN_KEY);

        return \is_string($token) && '' !== $token ? $token : null;
    }

    public function forgetPlacedOrder(): void
    {
        $this->session()?->remove(self::PLACED_ORDER_TOKEN_KEY);
    }

    /**
     * An order can also be placed with no request at all — a console command, a payment
     * notification handled outside a browser — and there is then no session to write to.
     */
    private function session(): ?SessionInterface
    {
        $request = $this->requestStack->getMainRequest();

        return $request?->hasSession() ? $request->getSession() : null;
    }
}
