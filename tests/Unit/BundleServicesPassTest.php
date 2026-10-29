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

use FlexyBundle\Components\Layouts\ProductDetails\Base as ProductDetails;
use FlexyBundle\DependencyInjection\Compiler\BundleServicesPass;
use FlexyBundle\FlexyBundle;
use FlexyBundle\Service\BundleSelectionService;
use FlexyBundle\Service\CartStockService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * The theme has to compile its container on a core that predates the product bundles: the service that needs the core's
 * bundle classes is dropped when they are not there, and the components that take it as an optional argument get null.
 *
 * The container here holds the services the way `config/services.yaml` registers them (autowired, by class), and none of
 * the core's: from the point of view of the autowiring, a class the core does not have and a service nobody registered
 * fail the same way, which is what the first test shows.
 */
final class BundleServicesPassTest extends TestCase
{
    private const MISSING_CORE_CLASS = 'Thelia\\Domain\\Catalog\\Bundle\\ClassOfAFutureCore';

    public function testWithoutThePassACoreWithoutTheBundleClassesBreaksTheContainer(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/BundleSelectionService/');

        $this->container()->compile();
    }

    public function testTheServiceIsDroppedWhenTheCoreLacksItsClassesAndItsConsumersGetNull(): void
    {
        $container = $this->container();
        $container->addCompilerPass(new BundleServicesPass([self::MISSING_CORE_CLASS]), PassConfig::TYPE_BEFORE_OPTIMIZATION);
        $container->compile();

        self::assertFalse($container->has(BundleSelectionService::class));
        self::assertTrue($container->has('consumer'));
        self::assertNull($container->get('consumer')->selection);
        self::assertTrue($container->has(CartStockService::class), 'the cart stock service already takes the core service as an optional argument');
    }

    public function testTheServiceIsKeptWhenTheCoreHasItsClasses(): void
    {
        $container = $this->container();

        (new BundleServicesPass())->process($container);

        self::assertTrue($container->hasDefinition(BundleSelectionService::class));
    }

    public function testTheProductSheetTakesTheServiceAsAnOptionalArgument(): void
    {
        $parameter = null;

        foreach ((new \ReflectionClass(ProductDetails::class))->getConstructor()?->getParameters() ?? [] as $candidate) {
            if (BundleSelectionService::class === $candidate->getType()?->getName()) {
                $parameter = $candidate;
            }
        }

        self::assertNotNull($parameter);
        self::assertTrue($parameter->allowsNull() && $parameter->isDefaultValueAvailable(), 'the sheet is built without the service on a core without bundles');
    }

    public function testThePassIsRegisteredByTheBundle(): void
    {
        $container = new ContainerBuilder();
        (new FlexyBundle())->build($container);

        $passes = array_map(static fn (object $pass): string => $pass::class, $container->getCompilerPassConfig()->getBeforeOptimizationPasses());

        self::assertContains(BundleServicesPass::class, $passes);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(BundleSelectionService::class, BundleSelectionService::class)->setAutowired(true);
        $container->register(CartStockService::class, CartStockService::class)->setAutowired(true)->setPublic(true);
        // What ProductDetails is to the service: an optional argument, null when nothing is registered.
        $container->register('consumer', BundleSelectionConsumer::class)->setAutowired(true)->setPublic(true);

        return $container;
    }
}

final class BundleSelectionConsumer
{
    public function __construct(public readonly ?BundleSelectionService $selection = null)
    {
    }
}
