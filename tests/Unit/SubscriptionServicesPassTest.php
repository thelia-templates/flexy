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

namespace FlexyBundle\Tests\Unit;

use FlexyBundle\DependencyInjection\Compiler\SubscriptionServicesPass;
use FlexyBundle\Service\Subscription\AccountSubscriptions;
use FlexyBundle\Service\Subscription\SubscriptionLineView;
use FlexyBundle\Service\Subscription\SubscriptionPlanOffers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * On a core that has no subscriptions the three services that read them are dropped, so the container still
 * compiles; with the core they are kept.
 */
final class SubscriptionServicesPassTest extends TestCase
{
    private const SERVICES = [SubscriptionPlanOffers::class, SubscriptionLineView::class, AccountSubscriptions::class];

    public function testTheServicesAreDroppedWhenTheFeatureClassIsMissing(): void
    {
        $container = $this->container();

        (new SubscriptionServicesPass('Thelia\\Domain\\Subscription\\NoSuchStateMachine'))->process($container);

        foreach (self::SERVICES as $service) {
            self::assertFalse($container->hasDefinition($service), $service.' is kept without the core feature.');
        }

        self::assertTrue($container->hasDefinition('another.service'), 'Only the subscription services go.');
    }

    public function testTheServicesAreKeptWhenTheFeatureClassExists(): void
    {
        $container = $this->container();

        (new SubscriptionServicesPass(self::class))->process($container);

        foreach (self::SERVICES as $service) {
            self::assertTrue($container->hasDefinition($service));
        }
    }

    public function testTheGuardIsTheClassTheCoreContractNames(): void
    {
        self::assertSame('Thelia\\Domain\\Subscription\\SubscriptionStateMachine', SubscriptionServicesPass::FEATURE_CLASS);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        foreach ([...self::SERVICES, 'another.service'] as $id) {
            $container->register($id, \stdClass::class);
        }

        return $container;
    }
}
