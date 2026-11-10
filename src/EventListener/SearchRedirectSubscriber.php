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
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\ViewCheckEvent;
use Thelia\Core\HttpKernel\Exception\RedirectException;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Search\SearchService;

final readonly class SearchRedirectSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RequestStack $requestStack,
        private LangService $langService,
        private ?SearchService $coreSearch = null,
    ) {
    }

    public function onViewCheck(ViewCheckEvent $event): void
    {
        if ('search' !== $event->getView() || $this->coreSearch === null) {
            return;
        }

        $request = $this->requestStack->getMainRequest();

        if ($request === null || !$request->isMethod('GET') || $request->query->getInt('page', 1) > 1 || $request->query->has('sort')) {
            return;
        }

        $term = trim($request->query->getString('query'));

        if ($term === '') {
            return;
        }

        $path = $this->coreSearch->redirectFor($term, (string) $this->langService->getLocale());

        if ($path !== null) {
            throw new RedirectException($path);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::VIEW_CHECK => 'onViewCheck',
        ];
    }
}
