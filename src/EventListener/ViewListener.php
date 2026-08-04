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
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Thelia\Core\TheliaHttpKernel;

class ViewListener implements EventSubscriberInterface
{
    public function beforeKernelView(ViewEvent $event): void
    {
        $request = $event->getRequest();

        if (null !== $request->attributes->get('_live_action')) {
            $request->attributes->set(TheliaHttpKernel::IGNORE_THELIA_VIEW, true);
        }
    }

    /**
     * {@inheritdoc}
     * api.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::VIEW => [
                ['beforeKernelView', 6],
            ],
        ];
    }
}
