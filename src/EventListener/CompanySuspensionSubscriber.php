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

use FlexyBundle\Service\CompanyAccount;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A buyer of a suspended company stays signed in and keeps their history, and the steps of the
 * checkout after the cart answer with the reason instead of going on: they are sent back to the
 * cart, which says why. The core refuses the order at the placement whatever this does; this is
 * the explanation the buyer would otherwise miss.
 */
final readonly class CompanySuspensionSubscriber implements EventSubscriberInterface
{
    /** The routes of the checkout that go beyond the cart. */
    private const ROUTES = [
        'checkout_delivery',
        'checkout_payment',
        'checkout_step',
        'checkout_gateway',
        'checkout_pay',
    ];

    public function __construct(
        private CompanyAccount $companyAccount,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'translator')]
        private TranslatorInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the router (32), so that the route of the request is known.
        return [KernelEvents::REQUEST => ['onRequest', 16]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !\in_array($request->attributes->get('_route'), self::ROUTES, true)) {
            return;
        }

        if (!$this->companyAccount->isSuspended()) {
            return;
        }

        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $this->translator->trans('Your company account is suspended: orders cannot be placed until it is reactivated.'));
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('checkout_cart')));
    }
}
