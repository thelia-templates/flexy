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
use Symfony\Component\Config\Definition\Processor;
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
        self::assertSame('Flexy', $config[0]['title']);
        self::assertNull($config[0]['viewports']['responsive']);
        self::assertSame(768, $config[0]['viewports']['Tablet']);
    }

    public function testThePrimaryColourOfTheThemeIsPrependedOnlyWhereThePackageKnowsIt(): void
    {
        $builder = $this->builder();
        $builder->registerExtension($this->extension('ux_showcase'));

        (new FlexyBundle())->prependExtension($this->configurator($builder), $builder);

        $config = $builder->getExtensionConfig('ux_showcase')[0];

        if (property_exists('OpenStudio\\UxShowcaseBundle\\ShowcaseConfiguration', 'primaryColor')) {
            self::assertSame(['primary_color' => '#ff4822'], $config['theme']);
        } else {
            self::assertArrayNotHasKey('theme', $config, 'An older package fails on the unknown key.');
        }
    }

    public function testThePrimaryColourOfTheApplicationWinsOverTheOneOfTheTheme(): void
    {
        if (!property_exists('OpenStudio\\UxShowcaseBundle\\ShowcaseConfiguration', 'primaryColor')) {
            self::markTestSkipped('The installed showcase package has no theme.primary_color.');
        }

        $bundle = new OpenStudioUxShowcaseBundle();
        $extension = $bundle->getContainerExtension();
        $builder = $this->builder();
        $builder->registerExtension($extension);

        (new FlexyBundle())->prependExtension($this->configurator($builder), $builder);
        $builder->loadFromExtension('ux_showcase', ['theme' => ['primary_color' => 'rebeccapurple']]);

        $configs = $builder->getExtensionConfig('ux_showcase');
        $merged = (new Processor())->processConfiguration($extension->getConfiguration([], $builder), $configs);

        self::assertSame('rebeccapurple', $merged['theme']['primary_color']);
        self::assertSame('Flexy', $merged['title']);
    }

    public function testTheBackgroundsAreOnlyTheOneTheThemeLetsShow(): void
    {
        $builder = $this->builder();
        $builder->registerExtension($this->extension('ux_showcase'));

        (new FlexyBundle())->prependExtension($this->configurator($builder), $builder);

        self::assertSame(['theme' => ['color' => '#fff5f1']], $builder->getExtensionConfig('ux_showcase')[0]['backgrounds']);
    }

    /**
     * The application keeps the hand: its own entries come after the one of the theme, and an entry
     * of the same name wins. The package merges `backgrounds` by key (only `viewports` is replaced as a
     * whole), so the application cannot drop `theme` but it can give it another colour.
     */
    public function testTheBackgroundsOfTheApplicationAreMergedAfterTheOneOfTheThemeAndWinByName(): void
    {
        $bundle = new OpenStudioUxShowcaseBundle();
        $extension = $bundle->getContainerExtension();
        $builder = $this->builder();
        $builder->registerExtension($extension);

        (new FlexyBundle())->prependExtension($this->configurator($builder), $builder);
        $builder->loadFromExtension('ux_showcase', ['backgrounds' => ['night' => '#000000', 'theme' => '#abcdef']]);

        $merged = (new Processor())->processConfiguration($extension->getConfiguration([], $builder), $builder->getExtensionConfig('ux_showcase'));

        self::assertSame(['theme', 'night'], array_keys($merged['backgrounds']));
        self::assertSame('#000000', $merged['backgrounds']['night']['color']);
        self::assertSame('#abcdef', $merged['backgrounds']['theme']['color']);
        self::assertArrayNotHasKey('light', $merged['backgrounds']);
    }

    public function testWithoutAnApplicationChoiceTheMergedBackgroundsAreTheOneOfTheTheme(): void
    {
        $bundle = new OpenStudioUxShowcaseBundle();
        $extension = $bundle->getContainerExtension();
        $builder = $this->builder();
        $builder->registerExtension($extension);

        (new FlexyBundle())->prependExtension($this->configurator($builder), $builder);

        $merged = (new Processor())->processConfiguration($extension->getConfiguration([], $builder), $builder->getExtensionConfig('ux_showcase'));

        self::assertSame(['theme'], array_keys($merged['backgrounds']));
        self::assertSame('#fff5f1', $merged['backgrounds']['theme']['color']);
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
