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

class FlexyBundle extends AbstractBundle
{
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

    private function prependConfigTwig(ContainerBuilder $containerBuilder): void
    {
        $containerBuilder->prependExtensionConfig('twig', [
            'paths' => [
                \dirname(__DIR__) . '/components' => 'Flexy',
                \dirname(__DIR__) . '/form' => 'FlexyForm',
                \dirname(__DIR__) . '/partials' => 'FlexyPartials',
            ],
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
        $containerBuilder->prependExtensionConfig('twig_component', [
            'anonymous_template_directory' => 'frontOffice/%thelia_front_template%/components/',
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

        $containerBuilder->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    // Declare the `assets/` entry and, moreover, place it first
                    // so that AssetMapper searches for the resource within the bundle directories
                    // before those of the project in which it is embedded.
                    \dirname(__DIR__) . '/assets',
                    \dirname(__DIR__) . '/assets/styles',
                    \dirname(__DIR__) . '/components',
                ],
                'vendor_dir' => '%kernel.project_dir%/templates/frontOffice/%thelia_front_template%/assets/vendor',
                'importmap_path' =>       '%kernel.project_dir%/templates/frontOffice/%thelia_front_template%/importmap.php',
                'public_prefix' =>        '/assets/frontOffice/%thelia_front_template%/',
                'excluded_patterns' => [
                    '*/*.html.twig',
                ],
            ],
        ]);
    }

    private function prependConfigUxIcons(ContainerBuilder $containerBuilder): void
    {
        $containerBuilder->prependExtensionConfig('ux_icons', [
            'icon_dir' => '%kernel.project_dir%/templates/frontOffice/%thelia_front_template%/assets/icons'
        ]);
    }

    private function prependConfigTailwind(ContainerBuilder $containerBuilder): void
    {
        $containerBuilder->prependExtensionConfig('symfonycasts_tailwind', [
            'input_css' => '%kernel.project_dir%/templates/frontOffice/%thelia_front_template%/assets/styles/app.css',
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
        $containerBuilder->prependExtensionConfig('stimulus', [
            'controller_paths' => [
                '%kernel.project_dir%/templates/frontOffice/%thelia_front_template%/assets/controllers',
                '%kernel.project_dir%/templates/frontOffice/%thelia_front_template%/components',
            ],
            'controllers_json' => '%kernel.project_dir%/templates/frontOffice/%thelia_front_template%/assets/controllers.json',
        ]);
    }

    private function prependConfigPackages(ContainerConfigurator $containerConfigurator): void
    {
        $containerConfigurator->import('../config/packages/*.yaml');
    }
}
