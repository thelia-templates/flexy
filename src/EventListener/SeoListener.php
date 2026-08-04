<?php

declare(strict_types=1);

namespace FlexyBundle\EventListener;

use SEOne\Event\SEOneSpecificEvents\SEOneMicroDataEvent;
use SEOne\Event\SEOneSpecificEvents\SEOnePageDescEvent;
use SEOne\Event\SEOneSpecificEvents\SEOnePageH1Event;
use SEOne\Event\SEOneSpecificEvents\SEOnePageTitleEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class SeoListener implements EventSubscriberInterface
{
    protected readonly Request $request;

    public function __construct(
        protected RequestStack $requestStack,
    ) {
        $this->request = $requestStack->getCurrentRequest();
    }

    public function overrideTitlePage(SEOnePageTitleEvent $event): void
    {}

    public function overrideDescPage(SEOnePageDescEvent $event): void
    {}

    public function overrideH1Page(SEOnePageH1Event $event): void
    {}

    public function overrideMicroDataPage(SEOneMicroDataEvent $event): void
    {}

    public static function getSubscribedEvents(): array
    {
        return [
            SEOnePageTitleEvent::BETTER_SEO_PAGE_TITLE => [
                ['overrideTitlePage', 128],
            ],
            SEOnePageDescEvent::BETTER_SEO_PAGE_DESC => [
                ['overrideDescPage', 128],
            ],
            SEOnePageh1Event::BETTER_SEO_PAGE_H1 => [
                ['overrideH1Page', 128],
            ],
            SEOneMicroDataEvent::BETTER_SEO_MICRO_DATA => [
                ['overrideMicroDataPage', 128],
            ]
        ];
    }
}
