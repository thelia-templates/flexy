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

namespace FlexyBundle\AccountMenu;

use FlexyBundle\Service\Subscription\AccountSubscriptions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\SecurityContext;
use Thelia\Model\Customer;

/**
 * "My subscriptions", after "My payment methods". The entry exists only for a signed-in customer, when the shop sells by subscription or the customer holds one, on a core that carries subscriptions: the
 * service it asks is then absent (SubscriptionServicesPass) and the provider offers nothing, so the menu is the one it was.
 */
final readonly class SubscriptionsAccountMenuItemProvider implements ShippedAccountMenuItemProviderInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'translator')]
        private TranslatorInterface $translator,
        private SecurityContext $securityContext,
        private ?AccountSubscriptions $subscriptions = null,
    ) {
    }

    public function getItems(): iterable
    {
        if (null === $this->subscriptions || !$this->securityContext->hasAuthenticatedCustomerUser()) {
            return [];
        }

        $customer = $this->securityContext->getCustomerUser();

        if (!$customer instanceof Customer || $customer->isGuest() || !$this->subscriptions->isOfferedTo($customer)) {
            return [];
        }

        return [
            new AccountMenuItem('subscriptions', $this->translator->trans('My subscriptions'), $this->urlGenerator->generate('account_subscriptions'), 450),
        ];
    }
}
