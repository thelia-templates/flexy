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

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Template\TemplateService;

class FlexyBundle extends AbstractBundle
{
    /** @var list<string>|null */
    private ?array $frontTemplateChain = null;

    #[\Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $this->importServices($container);
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
        $this->prependConfigPackages($container);
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
     *
     * @return list<string>
     */
    private function getFrontTemplateChain(ContainerBuilder $containerBuilder): array
    {
        if (null !== $this->frontTemplateChain) {
            return $this->frontTemplateChain;
        }

        $activeTemplate = $containerBuilder->hasParameter('thelia_front_template')
            ? (string) $containerBuilder->getParameter('thelia_front_template')
            : '';

        return $this->frontTemplateChain = TemplateService::getTemplateChainAbsolutePath(
            TemplateDefinition::FRONT_OFFICE_SUBDIR,
            $activeTemplate,
        );
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
     * The first template of the chain that ships the given file or directory. Settings such as
     * the Tailwind entry point or the importmap hold a single path: the nearest template that
     * provides one answers for the whole chain.
     *
     * @param list<string> $chain
     */
    private function findInTemplateChain(array $chain, string $relativePath, callable $exists): ?string
    {
        foreach ($chain as $templateDirectory) {
            if ($exists($templateDirectory . $relativePath)) {
                return $templateDirectory;
            }
        }

        return null;
    }

    private function prependConfigTwig(ContainerBuilder $containerBuilder): void
    {
        $paths = [];

        // A child template overriding a component must be searched before the template it
        // inherits from, hence the chain first and this bundle's own directories last.
        foreach ($this->getFrontTemplateChain($containerBuilder) as $templateDirectory) {
            $paths[$templateDirectory . '/components'] = 'Flexy';
            $paths[$templateDirectory . '/form'] = 'FlexyForm';
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
        // A single directory, resolved relative to the Twig paths of the project: the nearest
        // template of the chain that ships anonymous components answers for all of them.
        $templateDirectory = $this->findInTemplateChain(
            $this->getFrontTemplateChain($containerBuilder),
            '/components',
            is_dir(...),
        ) ?? \dirname(__DIR__);

        $containerBuilder->prependExtensionConfig('twig_component', [
            'anonymous_template_directory' => TemplateDefinition::FRONT_OFFICE_SUBDIR
                . '/' . basename($templateDirectory) . '/components/',
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
        foreach ($chain as $templateDirectory) {
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
        $importmapDirectory = $this->findInTemplateChain($chain, '/importmap.php', is_file(...))
            ?? \dirname(__DIR__);

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
        $iconDirectory = $this->findInTemplateChain(
            $this->getFrontTemplateChain($containerBuilder),
            '/assets/icons',
            is_dir(...),
        );

        $containerBuilder->prependExtensionConfig('ux_icons', [
            'icon_dir' => null === $iconDirectory
                ? '%kernel.project_dir%/templates/frontOffice/%thelia_front_template%/assets/icons'
                : $iconDirectory . '/assets/icons',
            // The bundle defaults this to ['fill' => 'currentColor'], which its precedence
            // applies over the fill a file declares rather than in place of a missing one.
            'default_icon_attributes' => [],
        ]);
    }

    private function prependConfigTailwind(ContainerBuilder $containerBuilder): void
    {
        $stylesDirectory = $this->findInTemplateChain(
            $this->getFrontTemplateChain($containerBuilder),
            '/assets/styles/app.css',
            is_file(...),
        );

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

        foreach ($chain as $templateDirectory) {
            $controllerPaths[] = $templateDirectory . '/assets/controllers';
            $controllerPaths[] = $templateDirectory . '/components';
        }

        $controllersJsonDirectory = $this->findInTemplateChain(
            $chain,
            '/assets/controllers.json',
            is_file(...),
        );

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
