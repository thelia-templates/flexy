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
 * Whether the session in hand has just placed an order, and which tunnel it walked.
 *
 * The confirmation page is reachable by typing its url, and it used to empty the cart of
 * whoever asked for it: someone halfway through the checkout lost what they had put in
 * it. The core already empties the cart when an order is placed, so the page only has
 * anything to clear for a session that actually got that far — which is what this
 * answers.
 *
 * It also keeps the steps that order was placed through, because the pages that come
 * after the placement have no cart left to read them off: the core empties it, and the
 * empty cart that replaces it no longer answers the questions the tunnel was built from.
 * A purely virtual order is where that shows — nothing to ship means no delivery step,
 * an empty cart is not virtual, and the confirmation page would put back a stop the
 * buyer never made.
 */
final readonly class PlacedOrderMemory
{
    private const PLACED_ORDER_ID_KEY = 'flexy.placed_order_id';

    private const PLACED_ORDER_STEPS_KEY = 'flexy.placed_order_steps';

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

    /**
     * The codes of the steps the order was placed through, in tunnel order.
     *
     * Codes and not the drawn bar: a wording is read again from the configuration in the
     * language of the page that shows it, while what has to be frozen is which stops
     * there were.
     *
     * Written even when there is nothing to write: a session may place a second order,
     * and leaving the first one's tunnel behind would draw it on the second one's
     * confirmation. Nothing to write means nothing is known, which is what the pages
     * that read this fall back from.
     *
     * @param list<string> $stepCodes
     */
    public function rememberTheStepsWalked(array $stepCodes): void
    {
        $this->session()?->set(self::PLACED_ORDER_STEPS_KEY, $stepCodes);
    }

    public function hasOne(): bool
    {
        return null !== $this->session()?->get(self::PLACED_ORDER_ID_KEY);
    }

    /**
     * The order this session placed last, so that the confirmation page can show it.
     *
     * The id comes from the session, written at the placement: never from the address of
     * the page, which anybody can type with any number in it.
     */
    public function placedOrderId(): ?int
    {
        $orderId = $this->session()?->get(self::PLACED_ORDER_ID_KEY);

        return is_numeric($orderId) && (int) $orderId > 0 ? (int) $orderId : null;
    }

    /**
     * @return list<string>|null null when this session has not placed an order, and when
     *                          it placed one before this memory existed
     */
    public function stepsWalked(): ?array
    {
        $codes = $this->session()?->get(self::PLACED_ORDER_STEPS_KEY);

        if (!\is_array($codes) || [] === $codes) {
            return null;
        }

        return array_values(array_map(strval(...), $codes));
    }

    public function forget(): void
    {
        $session = $this->session();

        $session?->remove(self::PLACED_ORDER_ID_KEY);
        $session?->remove(self::PLACED_ORDER_STEPS_KEY);
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
