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

use FlexyBundle\FlexyBundle;
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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\UX\Icons\DependencyInjection\UXIconsExtension;

/**
 * A shop may run a template that declares this one as its parent and ships almost nothing of
 * its own. Every path this bundle prepends then has to be looked up in the whole chain: the
 * entry stylesheet, the importmap and its vendor assets, the Stimulus controllers and the
 * icons all live in the parent, and pointing them at the active template alone leaves the
 * shop with a container that cannot even be compiled.
 */
final class FlexyBundlePrependTest extends TestCase
{
    private const FRONT_OFFICE = 'frontOffice';
    private const PARENT_TEMPLATE = 'flexy';
    private const EXTENSIONS_THE_BUNDLE_CONFIGURES = [
        'stimulus',
        'framework',
        'liip_imagine',
        'tales_from_a_dev_twig_extra_tailwind',
    ];

    private string $childTemplate;
    private string $childTemplateDirectory;
    private string $parentTemplateDirectory;

    protected function setUp(): void
    {
        $this->childTemplate = uniqid('flexy-child-', false);
        $this->childTemplateDirectory = THELIA_TEMPLATE_DIR.self::FRONT_OFFICE.DS.$this->childTemplate;
        $this->parentTemplateDirectory = THELIA_TEMPLATE_DIR.self::FRONT_OFFICE.DS.self::PARENT_TEMPLATE;

        (new Filesystem())->dumpFile(
            $this->childTemplateDirectory.DS.'template.xml',
            <<<XML
                <?xml version="1.0" encoding="UTF-8"?>
                <template xmlns="http://thelia.net/schema/dic/template">
                    <descriptive locale="en">
                        <title>A child of this template</title>
                    </descriptive>
                    <parent>flexy</parent>
                    <languages>
                        <language>en_US</language>
                    </languages>
                    <version>1.0.0</version>
                    <stability>prod</stability>
                </template>
                XML,
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->childTemplateDirectory);
    }

    public function testTheEntryStylesheetOfTheParentIsUsedWhenTheChildShipsNone(): void
    {
        $builder = $this->prependFor($this->childTemplate);

        self::assertSame(
            $this->parentTemplateDirectory.'/assets/styles/app.css',
            $this->configOf($builder, 'symfonycasts_tailwind')['input_css'],
        );
    }

    public function testTheImportmapAndTheVendorAssetsOfTheParentAreUsedWhenTheChildShipsNone(): void
    {
        $assetMapper = $this->configOf($this->prependFor($this->childTemplate), 'framework')['asset_mapper'];

        self::assertSame($this->parentTemplateDirectory.'/importmap.php', $assetMapper['importmap_path']);
        self::assertSame($this->parentTemplateDirectory.'/assets/vendor', $assetMapper['vendor_dir']);
    }

    public function testTheIconsOfTheParentAreUsedWhenTheChildShipsNone(): void
    {
        self::assertSame(
            $this->parentTemplateDirectory.'/assets/icons',
            $this->configOf($this->prependFor($this->childTemplate), 'ux_icons')['icon_dir'],
        );
    }

    /**
     * ux-icons applies its own configured attributes over the ones an SVG file carries
     * (precedence: file < configuration < invocation) and defaults that configuration to
     * `fill: currentColor`. Left at its default it repaints every icon the theme ships, so the
     * bundle has to blank it. Reading back the prepended array would not catch the key being
     * dropped: what decides the rendering is the configuration ux-icons ends up with once its
     * own definition tree has filled in every default, which is what is asserted here.
     */
    public function testNoAttributeIsAppliedOverTheOnesAnIconFileDeclares(): void
    {
        self::assertSame(
            [],
            $this->uxIconsConfigFor(self::PARENT_TEMPLATE)['default_icon_attributes'],
            'ux-icons would repaint the fill of every icon of the template.',
        );
    }

    /** The same, for a template that inherits its icons instead of shipping them. */
    public function testAChildInheritsBothTheIconsOfItsParentAndTheirAttributes(): void
    {
        $uxIcons = $this->uxIconsConfigFor($this->childTemplate);

        self::assertSame($this->parentTemplateDirectory.'/assets/icons', $uxIcons['icon_dir']);
        self::assertSame([], $uxIcons['default_icon_attributes']);
    }

    public function testTheStimulusControllersOfTheParentAreRegisteredForTheChild(): void
    {
        $stimulus = $this->configOf($this->prependFor($this->childTemplate), 'stimulus');

        self::assertContains($this->parentTemplateDirectory.'/assets/controllers', $stimulus['controller_paths']);
        self::assertContains($this->parentTemplateDirectory.'/components', $stimulus['controller_paths']);
        self::assertSame(
            $this->parentTemplateDirectory.'/assets/controllers.json',
            $stimulus['controllers_json'],
        );
    }

    public function testOnlyDirectoriesThatExistAreDeclaredToTheAssetMapper(): void
    {
        $assetMapper = $this->configOf($this->prependFor($this->childTemplate), 'framework')['asset_mapper'];

        foreach ($assetMapper['paths'] as $path) {
            self::assertDirectoryExists($path);
        }

        self::assertContains($this->parentTemplateDirectory.'/assets', $assetMapper['paths']);
    }

    public function testTheComponentsOfTheParentAnswerForTheChild(): void
    {
        $paths = $this->configOf($this->prependFor($this->childTemplate), 'twig')['paths'];

        self::assertSame('Flexy', $paths[$this->parentTemplateDirectory.'/components'] ?? null);
        self::assertSame('FlexyForm', $paths[$this->parentTemplateDirectory.'/form'] ?? null);
    }

    public function testATemplateThatInheritsFromNothingKeepsItsOwnDirectories(): void
    {
        $assetMapper = $this->configOf($this->prependFor(self::PARENT_TEMPLATE), 'framework')['asset_mapper'];

        self::assertSame($this->parentTemplateDirectory.'/importmap.php', $assetMapper['importmap_path']);
        self::assertNotContains($this->childTemplateDirectory.'/assets', $assetMapper['paths']);
    }

    /**
     * @return array<string, mixed>
     */
    private function configOf(ContainerBuilder $builder, string $extension): array
    {
        $configs = $builder->getExtensionConfig($extension);

        self::assertNotSame([], $configs, 'Nothing was prepended for "'.$extension.'".');

        return $configs[0];
    }

    /**
     * The configuration ux-icons really runs on: everything the bundle prepended for it, merged
     * and completed by the extension's own definition tree.
     *
     * @return array<string, mixed>
     */
    private function uxIconsConfigFor(string $frontTemplate): array
    {
        return (new Processor())->processConfiguration(
            new UXIconsExtension(),
            $this->prependFor($frontTemplate)->getExtensionConfig('ux_icons'),
        );
    }

    private function prependFor(string $frontTemplate): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('thelia_front_template', $frontTemplate);
        $builder->setParameter('kernel.bundles_metadata', [
            'FrameworkBundle' => [
                'path' => \dirname((string) (new \ReflectionClass(FrameworkBundle::class))->getFileName()),
            ],
        ]);

        // The bundle prepends the Stimulus configuration only when the bundle is installed,
        // and it imports its own config/packages/, which names extensions of its own: the
        // container has to answer for all of them, and nothing here loads any of them.
        foreach (self::EXTENSIONS_THE_BUNDLE_CONFIGURES as $alias) {
            $builder->registerExtension(new class($alias) extends Extension {
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
            });
        }

        (new FlexyBundle())->prependExtension($this->configuratorFor($builder), $builder);

        return $builder;
    }

    private function configuratorFor(ContainerBuilder $builder): ContainerConfigurator
    {
        $configDirectory = \dirname(__DIR__, 2).'/config';
        $fileLocator = new FileLocator($configDirectory);

        $phpLoader = new PhpFileLoader($builder, $fileLocator);
        $phpLoader->setResolver(new LoaderResolver([
            new YamlFileLoader($builder, $fileLocator),
            $phpLoader,
            new GlobFileLoader($builder, $fileLocator),
            new DirectoryLoader($builder, $fileLocator),
        ]));

        $instanceof = [];

        return new ContainerConfigurator(
            $builder,
            $phpLoader,
            $instanceof,
            $configDirectory.'/services.php',
            $configDirectory.'/services.php',
        );
    }
}
