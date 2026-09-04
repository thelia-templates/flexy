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
use Thelia\Model\Order;

/**
 * Whether the session in hand has just placed an order.
 *
 * The confirmation page is reachable by typing its url, and it used to empty the cart of
 * whoever asked for it: someone halfway through the checkout lost what they had put in
 * it. The core already empties the cart when an order is placed, so the page only has
 * anything to clear for a session that actually got that far — which is what this
 * answers.
 */
final readonly class PlacedOrderMemory
{
    private const PLACED_ORDER_ID_KEY = 'flexy.placed_order_id';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function remember(Order $order): void
    {
        $orderId = $order->getId();

        if (null === $orderId) {
            return;
        }

        $this->session()?->set(self::PLACED_ORDER_ID_KEY, $orderId);
    }

    public function hasOne(): bool
    {
        return null !== $this->session()?->get(self::PLACED_ORDER_ID_KEY);
    }

    public function forget(): void
    {
        $this->session()?->remove(self::PLACED_ORDER_ID_KEY);
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
