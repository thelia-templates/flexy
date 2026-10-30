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

use FlexyBundle\Service\BundleSelectionService;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Thelia\Domain\Catalog\Bundle\BundleCompositionResolver;
use Thelia\Domain\Pricing\BundleQuoter;

/**
 * The theme ships on its own release cycle and runs on a core that may predate the product bundles. A service of
 * `src/` is autowired from its constructor, and one that asks for a class the core does not have fails the compilation
 * of the whole container (the site answers 500, not just the product sheet).
 *
 * The services that need the core's bundle classes are dropped when those classes are missing. The components that
 * take them as optional arguments (`?BundleSelectionService $x = null`) then get null, which is what they already
 * handle: a core without bundles never shows one.
 */
final class BundleServicesPass implements CompilerPassInterface
{
    /**
     * The classes the core ships with the bundles, which the services below cannot be built without.
     */
    public const CORE_CLASSES = [
        BundleCompositionResolver::class,
        BundleQuoter::class,
    ];

    /**
     * @param list<string>       $coreClasses
     * @param list<class-string> $services
     */
    public function __construct(
        private readonly array $coreClasses = self::CORE_CLASSES,
        private readonly array $services = [BundleSelectionService::class],
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        foreach ($this->coreClasses as $coreClass) {
            if (!class_exists($coreClass)) {
                $this->drop($container);

                return;
            }
        }
    }

    private function drop(ContainerBuilder $container): void
    {
        foreach ($this->services as $service) {
            if ($container->hasDefinition($service)) {
                $container->removeDefinition($service);
            }
        }
    }
}
