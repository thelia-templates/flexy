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

namespace FlexyBundle;

use FlexyBundle\DependencyInjection\Compiler\BundleServicesPass;
use FlexyBundle\DependencyInjection\Compiler\TwigComponentDebugDirectoryPass;
use FlexyBundle\EventListener\TailwindMinifiedBuildListener;
use FlexyBundle\Template\FrontTemplateChain;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\Event\PreAssetsCompileEvent;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\UX\Icons\Registry\LocalSvgIconRegistry;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;

class FlexyBundle extends AbstractBundle
{
    private ?FrontTemplateChain $frontTemplateChain = null;

    #[\Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new TwigComponentDebugDirectoryPass());
        $container->addCompilerPass(new BundleServicesPass());
    }

    /**
     * @param array<string, mixed> $config
     */
    #[\Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $this->importServices($container);
        $this->registerTemplateChainIcons($container, $builder);
        $this->registerTailwindMinifiedBuildListener($container, $builder);
    }


    #[\Override]
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $this->prependConfigTwig($builder);
        $this->prependConfigTwigComponent($builder);
        $this->prependConfigAssetMapper($builder);
        $this->prependConfigUxIcons($builder);
        $this->prependConfigTailwind($builder);
        $this->prependConfigStimulus($builder);
        $this->prependConfigTranslator($builder);
        $this->prependConfigPackages($container);
    }


    private function registerTailwindMinifiedBuildListener(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // A child template may run without the Tailwind bundle: nothing to build then. The
        // extension cannot be asked: loadExtension() runs in a temporary container that holds
        // this bundle's extension alone, so the bundles of the kernel are read instead.
        $bundles = $builder->hasParameter('kernel.bundles') ? $builder->getParameter('kernel.bundles') : [];

        if (!\in_array('Symfonycasts\\TailwindBundle\\SymfonycastsTailwindBundle', $bundles, true) || !$this->isAssetMapperAvailable($builder)) {
            return;
        }

        $container->services()
            ->set(TailwindMinifiedBuildListener::class)
            ->args([service_closure('tailwind.builder'), param('kernel.debug')])
            ->tag('kernel.event_listener', ['event' => PreAssetsCompileEvent::class]);
    }

    private function importServices(ContainerConfigurator $containerConfigurator): void
    {
        $containerConfigurator->import('../config/services.yaml');

        $containerConfigurator->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();
    }

    /**
     * The directory of the active front template, then the directories of the templates it
     * inherits from, nearest first. A child template ships only what it overrides - a colour
     * scheme, a logo, a handful of pages - and everything else has to be found in its parents.
     *
     * The chain is read off the template.xml descriptors, which is all that can be read here:
     * a prepended configuration is built while the container is compiled, long before the
     * kernel boots.
     */
    private function getFrontTemplateChain(ContainerBuilder $containerBuilder): FrontTemplateChain
    {
        if (null !== $this->frontTemplateChain) {
            return $this->frontTemplateChain;
        }

        $activeTemplate = $containerBuilder->hasParameter('thelia_front_template')
            ? (string) $containerBuilder->getParameter('thelia_front_template')
            : '';

        return $this->frontTemplateChain = new FrontTemplateChain($activeTemplate);
    }

    /**
     * @param list<string> $directories
     *
     * @return list<string>
     */
    private function keepExistingDirectories(array $directories): array
    {
        return array_values(array_unique(array_filter($directories, is_dir(...))));
    }

    /**
     * `@theme_flexy` for the directory of the flexy template: a name a page can write, whatever
     * the characters of the directory name.
     */
    public static function templateNamespace(string $templateDirectory): string
    {
        return 'theme_' . preg_replace('/[^A-Za-z0-9_]/', '_', basename($templateDirectory));
    }

    private function prependConfigTwig(ContainerBuilder $containerBuilder): void
    {
        $paths = [];
        $namespaces = [];

        // A child template overriding a component must be searched before the template it
        // inherits from, hence the chain first and this bundle's own directories last.
        foreach ($this->getFrontTemplateChain($containerBuilder)->inherited() as $templateDirectory) {
            $namespace = self::templateNamespace($templateDirectory);

            // `my-shop` and `my_shop` both read `theme_my_shop`: Twig would merge the two roots
            // under one namespace, and `@theme_my_shop/base.html.twig` would name whichever
            // comes first, the page extending itself instead of its parent.
            if (isset($namespaces[$namespace])) {
                throw new \LogicException(\sprintf(
                    'The front templates "%s" and "%s" both map to the Twig namespace "@%s": rename one of them.',
                    $namespaces[$namespace],
                    $templateDirectory,
                    $namespace,
                ));
            }

            $namespaces[$namespace] = $templateDirectory;

            $paths[$templateDirectory . '/components'] = 'Flexy';
            $paths[$templateDirectory . '/form'] = 'FlexyForm';

            // The root of each template under its own namespace, so that a child template
            // extends a page of its parent instead of copying it whole:
            //     {% extends '@theme_flexy/base.html.twig' %}
            // A root page asked for by its bare name still resolves through the chain, nearest
            // first; the namespace is what a page needs to name the one it inherits from.
            $paths[$templateDirectory] = $namespace;
        }

        $paths[\dirname(__DIR__) . '/components'] = 'Flexy';
        $paths[\dirname(__DIR__) . '/form'] = 'FlexyForm';

        $containerBuilder->prependExtensionConfig('twig', [
            'paths' => array_filter($paths, is_dir(...), \ARRAY_FILTER_USE_KEY),
            // The theme is intentionally NOT registered globally (a global form
            // theme would also style the back-office forms): every template
            // rendering a form declares it explicitly with
            // {% form_theme form with flexy_form_themes only %}.
            'globals' => [
                'flexy_form_themes' => [
                    '@FlexyForm/flexy_form_theme.html.twig',
                ],
            ],
        ]);
    }
    private function prependConfigTwigComponent(ContainerBuilder $containerBuilder): void
    {
        // Anonymous components (a template without a PHP class, `Fields/*` for the most part)
        // are looked up in a single directory, which the finder resolves through the Twig
        // loader: named by the `@Flexy` namespace, that directory is the whole chain, the
        // component directory of the active template first, the one of this bundle last. A
        // filesystem path here would be the nearest `components/` alone, and a child template
        // that ships one component would lose every anonymous component of its parent.
        // `debug:twig-component` cannot read a namespace there: TwigComponentDebugDirectoryPass
        // hands it a filesystem path of its own.
        $containerBuilder->prependExtensionConfig('twig_component', [
            'anonymous_template_directory' => '@Flexy',
            'defaults' => [
                'FlexyBundle\\Components\\' => [
                    'template_directory' => '@Flexy',
                    'name_prefix' => '',
                ],
            ],
        ]);
    }

    private function isAssetMapperAvailable(ContainerBuilder $container): bool
    {
        if (!interface_exists(AssetMapperInterface::class)) {
            return false;
        }

        // check that FrameworkBundle 6.3 or higher is installed
        $bundlesMetadata = $container->getParameter('kernel.bundles_metadata');
        if (!\is_array($bundlesMetadata) || !isset($bundlesMetadata['FrameworkBundle'])) {
            return false;
        }

        return is_file($bundlesMetadata['FrameworkBundle']['path'] . '/Resources/config/asset_mapper.php');
    }

    private function prependConfigAssetMapper(ContainerBuilder $containerBuilder): void
    {
        if (!$this->isAssetMapperAvailable($containerBuilder)) {
            return;
        }

        $chain = $this->getFrontTemplateChain($containerBuilder);

        $paths = [];

        // The active template first, then the templates it inherits from: an asset the child
        // redefines must be found before the one it replaces.
        foreach ($chain->inherited() as $templateDirectory) {
            $paths[] = $templateDirectory . '/assets';
            $paths[] = $templateDirectory . '/assets/styles';
            $paths[] = $templateDirectory . '/components';
        }

        // Declare the `assets/` entry and, moreover, place it first
        // so that AssetMapper searches for the resource within the bundle directories
        // before those of the project in which it is embedded.
        $paths[] = \dirname(__DIR__) . '/assets';
        $paths[] = \dirname(__DIR__) . '/assets/styles';
        $paths[] = \dirname(__DIR__) . '/components';

        // The importmap and the vendor assets it downloads belong to the same template: a
        // child template that ships none of its own runs on those of its parent.
        // directories() always ends with this template, which ships an importmap: never null.
        $importmapDirectory = $chain->nearest('importmap.php', is_file(...))
            ?? throw new \LogicException('This template ships an importmap.php.');

        $containerBuilder->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => $this->keepExistingDirectories($paths),
                'vendor_dir' => $importmapDirectory . '/assets/vendor',
                'importmap_path' => $importmapDirectory . '/importmap.php',
                'public_prefix' =>        '/assets/frontOffice/%thelia_front_template%/',
                'excluded_patterns' => [
                    '*/*.html.twig',
                ],
            ],
        ]);
    }

    private function prependConfigUxIcons(ContainerBuilder $containerBuilder): void
    {
        // ux-icons reads one directory. It is this bundle's own, always present: the icons a
        // template of the chain adds or replaces come from the registries
        // registerTemplateChainIcons() declares, which answer before this one.
        $containerBuilder->prependExtensionConfig('ux_icons', [
            'icon_dir' => $this->getFrontTemplateChain($containerBuilder)->ownInChain() . '/assets/icons',
            // The bundle defaults this to ['fill' => 'currentColor'], which its precedence
            // applies over the fill a file declares rather than in place of a missing one.
            'default_icon_attributes' => [],
        ]);
    }

    /**
     * One icon registry per template of the chain that ships icons, this bundle's excepted (it
     * is the configured directory). The nearest template answers first: an icon it ships under
     * the name of one of ours replaces it everywhere `ux_icon()` asks for that name, and an icon
     * it does not ship falls through to the next template, down to ours.
     *
     * ux-icons chains its registries by the priority of the `ux_icons.registry` tag; its own
     * local registry sits at 10.
     */
    private function registerTemplateChainIcons(ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void
    {
        $chain = $this->getFrontTemplateChain($containerBuilder)->inherited();
        $priority = 20 + \count($chain);
        $services = $containerConfigurator->services();

        foreach ($chain as $templateDirectory) {
            --$priority;

            if (FrontTemplateChain::isOwn($templateDirectory) || !is_dir($iconDirectory = $templateDirectory . '/assets/icons')) {
                continue;
            }

            // LocalSvgIconRegistry and the `.ux_icons.icon_factory` service are internal to
            // ux-icons: checked against symfony/ux-icons 2.36.1, whose constructor reads
            // (IconFactory $iconFactory, string $iconDir, array $iconSetPaths = []).
            // FlexyBundlePrependTest compiles this definition against the real extension.
            $services->set('flexy.icon_registry.' . basename($templateDirectory), LocalSvgIconRegistry::class)
                ->args([service('.ux_icons.icon_factory'), $iconDirectory])
                ->tag('ux_icons.registry', ['priority' => $priority]);
        }
    }

    /**
     * The `translations/` catalogues of the templates of the chain, parents first: the framework
     * loads the paths in this order and a key read later replaces the one read before, so the
     * nearest template has the last word. Ours is left out: a bundle's `translations/` directory
     * is registered by the framework itself, before every configured path.
     */
    private function prependConfigTranslator(ContainerBuilder $containerBuilder): void
    {
        $paths = [];

        foreach (array_reverse($this->getFrontTemplateChain($containerBuilder)->inherited()) as $templateDirectory) {
            if (FrontTemplateChain::isOwn($templateDirectory)) {
                continue;
            }

            if (is_dir($translationsDirectory = $templateDirectory . '/translations')) {
                $paths[] = $translationsDirectory;
            }
        }

        if ([] === $paths) {
            return;
        }

        $containerBuilder->prependExtensionConfig('framework', [
            'translator' => ['paths' => $paths],
        ]);
    }

    private function prependConfigTailwind(ContainerBuilder $containerBuilder): void
    {
        $stylesDirectory = $this->getFrontTemplateChain($containerBuilder)
            ->nearestInherited('assets/styles/app.css', is_file(...));

        $containerBuilder->prependExtensionConfig('symfonycasts_tailwind', [
            'input_css' => null === $stylesDirectory
                ? '%kernel.project_dir%/templates/frontOffice/%thelia_front_template%/assets/styles/app.css'
                : $stylesDirectory . '/assets/styles/app.css',
            'binary_version' => 'v4.3.0',

        ]);
    }

    private function prependConfigStimulus(ContainerBuilder $containerBuilder): void
    {
        if (!$containerBuilder->hasExtension('stimulus')) {
            return;
        }

        // Stimulus feeds a single, application-wide controller registry, so these paths must follow
        // the active front template rather than this bundle's own location: bundles.php loads
        // FlexyBundle unconditionally, and a hardcoded dirname(__DIR__) would keep registering
        // Flexy's controllers even when another front theme is active. This mirrors what every
        // other prepend in this class already does. asset_mapper.paths deliberately keeps
        // dirname(__DIR__): there it is this bundle's own directory that must be searched first.
        // The chain follows the same rule: a template that inherits from another registers the
        // controllers of both, and one that inherits from nothing registers only its own.
        $chain = $this->getFrontTemplateChain($containerBuilder);

        $controllerPaths = [];

        foreach ($chain->inherited() as $templateDirectory) {
            $controllerPaths[] = $templateDirectory . '/assets/controllers';
            $controllerPaths[] = $templateDirectory . '/components';
        }

        $controllersJsonDirectory = $chain->nearestInherited('assets/controllers.json', is_file(...));

        $containerBuilder->prependExtensionConfig('stimulus', [
            'controller_paths' => $this->keepExistingDirectories($controllerPaths),
            'controllers_json' => null === $controllersJsonDirectory
                ? '%kernel.project_dir%/templates/frontOffice/%thelia_front_template%/assets/controllers.json'
                : $controllersJsonDirectory . '/assets/controllers.json',
        ]);
    }

    private function prependConfigPackages(ContainerConfigurator $containerConfigurator): void
    {
        $containerConfigurator->import('../config/packages/*.yaml');
    }
}
