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

namespace FlexyBundle\Service\Newsletter;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Newsletter\NewsletterEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Model\Customer;
use Thelia\Model\NewsletterQuery;

readonly class NewsletterProcessor
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private Request $httpRequest,
    ) {
    }

    public function subscribeToNewsletter(
        Customer $customer,
    ): void {
        $newsletterEmail = $customer->getEmail();
        $newsletterEvent = (new NewsletterEvent(
            $newsletterEmail,
            $this->httpRequest->getSession()->getLang()?->getLocale()
        ))
            ->setFirstname($customer->getFirstname())
            ->setLastname($customer->getLastname());

        if (null !== $newsletter = NewsletterQuery::create()->findOneByEmail($newsletterEmail)) {
            $newsletterEvent->setId((string) $newsletter->getId());
            $this->eventDispatcher->dispatch($newsletterEvent, TheliaEvents::NEWSLETTER_UPDATE);

            return;
        }

        $this->eventDispatcher->dispatch($newsletterEvent, TheliaEvents::NEWSLETTER_SUBSCRIBE);
    }
}
