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

namespace FlexyBundle\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Thelia\Core\Event\Cart\CartItemQuantityAdjustedEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Localization\Service\LangService;

/**
 * A line of a cart read back no longer met the rules of its combination (a cart restored, rules
 * changed since, a sale unit switched) and the core brought it down or removed it. That happens
 * while some page reads the cart, rarely the one the shopper looks at: the line and the core's
 * sentence are left in the flash bag, and the cart and the checkout pages tell them once
 * (checkout-base.html.twig), as a status rather than an error — nothing was refused, the cart
 * was set right.
 *
 * A core without quantity rules has no such event: nothing is subscribed.
 */
final readonly class QuantityAdjustedFlashListener implements EventSubscriberInterface
{
    public const FLASH_KEY = 'flexy.quantity_adjusted';

    public function __construct(
        private RequestStack $requestStack,
        private LangService $langService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        if (!\defined(TheliaEvents::class.'::CART_ITEM_QUANTITY_ADJUSTED')) {
            return [];
        }

        return [\constant(TheliaEvents::class.'::CART_ITEM_QUANTITY_ADJUSTED') => 'remember'];
    }

    public function remember(CartItemQuantityAdjustedEvent $event): void
    {
        $message = trim($event->getMessage());
        $request = $this->requestStack->getMainRequest();

        if ('' === $message || null === $request || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();

        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $session->getFlashBag()->add(self::FLASH_KEY, [
            'product' => (string) $event->getCartItem()->getProduct(null, $this->langService->getLocale())->getTitle(),
            'message' => $message,
        ]);
    }
}
