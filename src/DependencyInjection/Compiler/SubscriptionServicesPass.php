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

namespace FlexyBundle\DependencyInjection\Compiler;

use FlexyBundle\Service\Subscription\AccountSubscriptions;
use FlexyBundle\Service\Subscription\SubscriptionLineView;
use FlexyBundle\Service\Subscription\SubscriptionPlanOffers;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The theme runs on a core that may predate the subscriptions. A service of `src/` is autowired from its
 * constructor, and one that asks for a class the core does not have fails the compilation of the whole container.
 *
 * The three services that read the core's subscriptions are dropped when its state machine (the feature guard) is
 * missing. Everything that uses them takes them as an optional argument and gets null: the product page offers no plan,
 * the cart and the menu are unchanged, and the routes of the account answer 404.
 */
final class SubscriptionServicesPass implements CompilerPassInterface
{
    public const FEATURE_CLASS = 'Thelia\\Domain\\Subscription\\SubscriptionStateMachine';

    /**
     * @param list<class-string> $services
     */
    public function __construct(
        private readonly string $featureClass = self::FEATURE_CLASS,
        private readonly array $services = [SubscriptionPlanOffers::class, SubscriptionLineView::class, AccountSubscriptions::class],
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        if (class_exists($this->featureClass)) {
            return;
        }

        foreach ($this->services as $service) {
            if ($container->hasDefinition($service)) {
                $container->removeDefinition($service);
            }
        }
    }
}
