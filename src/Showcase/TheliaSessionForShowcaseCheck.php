<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 */

namespace FlexyBundle\Showcase;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session as SymfonySession;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Thelia\Core\HttpFoundation\Session\Session as TheliaSession;
use Thelia\Model\Lang;

/**
 * `ux-showcase:check` renders every example through sub-requests carrying a plain Symfony session,
 * and the theme asks its session for what only Thelia's has (`getLang()`, `setReturnToUrl()`): every
 * component that reads the language fails there, though it renders in a browser.
 *
 * A request that carries a session that is not Thelia's gets one that is. A request of the shop
 * always has it, so this changes nothing for a visitor.
 */
final class TheliaSessionForShowcaseCheck implements EventSubscriberInterface
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 1000]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!self::isPlainSession($request)) {
            return;
        }

        // The services of the shop read the session of the main request, which the check command
        // created before any request event: it is replaced too, and the request shares its session.
        $main = $this->requestStack->getMainRequest() ?? $request;

        if (self::isPlainSession($main)) {
            $session = new TheliaSession(new MockArraySessionStorage());
            // What the language listener of the shop does on the first request of a visitor.
            $session->setLang(Lang::getDefaultLanguage());
            $main->setSession($session);
        }

        $request->setSession($main->getSession());
    }

    private static function isPlainSession(Request $request): bool
    {
        return $request->hasSession() && SymfonySession::class === $request->getSession()::class;
    }
}
