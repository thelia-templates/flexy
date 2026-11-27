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

namespace FlexyBundle\Tests\Unit\Showcase;

use FlexyBundle\FlexyBundle;
use FlexyBundle\Showcase\FlexyComponents;
use FlexyBundle\Showcase\FlexyExampleProvider;
use FlexyBundle\Showcase\FlexyLibrary;
use FlexyBundle\Showcase\TheliaCheckRequestFactory;
use OpenStudio\UxShowcaseBundle\Library\ComponentLibraryInterface;
use OpenStudio\UxShowcaseBundle\OpenStudioUxShowcaseBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\DirectoryLoader;
use Symfony\Component\DependencyInjection\Loader\GlobFileLoader;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * The component showcase is a suggestion: the adapter is registered only where the package is
 * installed, and the theme builds and runs the same without it.
 */
final class ShowcaseRegistrationTest extends TestCase
{
    private const SHOWCASE_SERVICES = [
        FlexyLibrary::class,
        FlexyExampleProvider::class,
        FlexyComponents::class,
        TheliaCheckRequestFactory::class,
    ];

    public function testWithoutThePackageNoShowcaseServiceIsRegistered(): void
    {
        $builder = $this->load(showcaseInstalled: false);

        foreach (self::SHOWCASE_SERVICES as $service) {
            self::assertFalse($builder->hasDefinition($service), $service);
        }

        self::assertSame([], array_filter(
            array_keys($builder->getDefinitions()),
            static fn (string $id): bool => str_starts_with($id, 'FlexyBundle\\Showcase\\'),
        ));
    }

    public function testWithThePackageButNotItsBundleNoShowcaseServiceIsRegistered(): void
    {
        // Installed but not registered in this environment: there is no ComponentCatalog to autowire.
        $builder = $this->load(showcaseInstalled: true, bundleRegistered: false);

        self::assertSame([], array_filter(
            array_keys($builder->getDefinitions()),
            static fn (string $id): bool => str_starts_with($id, 'FlexyBundle\\Showcase\\'),
        ));
        self::assertTrue($builder->hasDefinition(\FlexyBundle\Toolkit\StoryFinder::class));
    }

    public function testTheBreakpointsStylesheetIsAResourceOfTheContainer(): void
    {
        $builder = $this->builder();
        $builder->registerExtension($this->extension('ux_showcase'));

        (new FlexyBundle())->prependExtension($this->configurator($builder), $builder);

        $files = array_map(static fn ($resource): string => (string) $resource, $builder->getResources());

        self::assertNotEmpty(array_filter($files, static fn (string $file): bool => str_ends_with($file, 'assets/styles/variables.css')));
    }

    public function testWithoutThePackageTheServicesOfTheThemeAreStillThere(): void
    {
        $builder = $this->load(showcaseInstalled: false);

        self::assertTrue($builder->hasDefinition(\FlexyBundle\Toolkit\StoryFinder::class));
        self::assertTrue($builder->hasDefinition(\FlexyBundle\Controller\ToolkitController::class));
    }

    public function testWithThePackageTheAdapterIsRegistered(): void
    {
        $builder = $this->load(showcaseInstalled: true);

        foreach (self::SHOWCASE_SERVICES as $service) {
            self::assertTrue($builder->hasDefinition($service), $service);
        }
    }

    public function testTheRealGuardFollowsThePackage(): void
    {
        $builder = $this->builder();
        $builder->setParameter('kernel.bundles', ['OpenStudioUxShowcaseBundle' => OpenStudioUxShowcaseBundle::class]);

        (new FlexyBundle())->loadExtension([], $this->configurator($builder), $builder);

        self::assertSame(
            interface_exists(ComponentLibraryInterface::class),
            $builder->hasDefinition(FlexyLibrary::class),
            'The adapter must be registered if and only if the showcase interfaces exist.',
        );
    }

    public function testTheExampleProviderIsNotAutoconfiguredSoTheShowcaseDoesNotFeedItTwice(): void
    {
        $builder = $this->load(showcaseInstalled: true);

        self::assertFalse($builder->getDefinition(FlexyExampleProvider::class)->isAutoconfigured());
        self::assertTrue($builder->getDefinition(FlexyLibrary::class)->isAutoconfigured());
    }

    public function testTheCheckRequestFactoryIsAutoconfiguredSoTheCheckCommandFindsIt(): void
    {
        $builder = $this->load(showcaseInstalled: true);

        self::assertTrue($builder->getDefinition(TheliaCheckRequestFactory::class)->isAutoconfigured());
    }

    public function testThePreviewWidthsAreTheBreakpointsOfTheThemeWhenTheShowcaseIsLoaded(): void
    {
        $builder = $this->builder();
        $builder->registerExtension($this->extension('ux_showcase'));

        (new FlexyBundle())->prependExtension($this->configurator($builder), $builder);

        $config = $builder->getExtensionConfig('ux_showcase');

        self::assertCount(1, $config);
        self::assertSame(['responsive', 'Mobile S', 'Mobile M', 'Mobile L', 'Tablet', 'Small Desktop', 'Desktop', 'Large Desktop', '3xl'], array_keys($config[0]['viewports']));
        self::assertNull($config[0]['viewports']['responsive']);
        self::assertSame(768, $config[0]['viewports']['Tablet']);
    }

    public function testNoShowcaseConfigurationIsPrependedWhenTheExtensionIsNotThere(): void
    {
        $builder = $this->builder();

        (new FlexyBundle())->prependExtension($this->configurator($builder), $builder);

        self::assertSame([], $builder->getExtensionConfig('ux_showcase'));
    }

    private function load(bool $showcaseInstalled, bool $bundleRegistered = true): ContainerBuilder
    {
        $builder = $this->builder();
        $builder->setParameter('kernel.bundles', $bundleRegistered ? ['OpenStudioUxShowcaseBundle' => OpenStudioUxShowcaseBundle::class] : []);
        $bundle = new class($showcaseInstalled) extends FlexyBundle {
            public function __construct(private readonly bool $showcaseInstalled)
            {
            }

            protected function isShowcaseInstalled(): bool
            {
                return $this->showcaseInstalled;
            }
        };

        $bundle->loadExtension([], $this->configurator($builder), $builder);

        return $builder;
    }

    private function builder(): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('thelia_front_template', 'flexy');
        $builder->setParameter('kernel.bundles_metadata', [
            'FrameworkBundle' => ['path' => \dirname((string) (new \ReflectionClass(FrameworkBundle::class))->getFileName())],
        ]);

        // prependExtension() prepends the configuration of these extensions only when they exist.
        foreach (['stimulus', 'framework', 'liip_imagine', 'tales_from_a_dev_twig_extra_tailwind'] as $alias) {
            $builder->registerExtension($this->extension($alias));
        }

        return $builder;
    }

    private function extension(string $alias): Extension
    {
        return new class($alias) extends Extension {
            public function __construct(private readonly string $alias)
            {
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return $this->alias;
            }
        };
    }

    private function configurator(ContainerBuilder $builder): ContainerConfigurator
    {
        $configDirectory = \dirname(__DIR__, 3).'/config';
        $fileLocator = new FileLocator($configDirectory);

        $phpLoader = new PhpFileLoader($builder, $fileLocator);
        $phpLoader->setResolver(new LoaderResolver([
            new YamlFileLoader($builder, $fileLocator),
            $phpLoader,
            new GlobFileLoader($builder, $fileLocator),
            new DirectoryLoader($builder, $fileLocator),
        ]));

        $instanceof = [];

        return new ContainerConfigurator($builder, $phpLoader, $instanceof, $configDirectory.'/services.php', $configDirectory.'/services.php');
    }
}
