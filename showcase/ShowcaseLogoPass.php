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

namespace FlexyBundle\Showcase;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\ExpressionLanguage\Expression;

/**
 * The logo of the theme is the one of its toolkit, `images/logo.png`. The public URL of an asset
 * carries a digest that changes with the file, so it is read from the asset mapper when the service
 * is built, not written in a configuration.
 *
 * The application keeps the hand: a `logo` set in its `ux_showcase` configuration is left alone.
 * This class is not in `src/` on purpose: it names the showcase package, which is optional.
 */
final class ShowcaseLogoPass implements CompilerPassInterface
{
    public const string CONFIGURATION = 'OpenStudio\\UxShowcaseBundle\\ShowcaseConfiguration';

    public const string LOGO = 'images/logo.png';

    /** The position of `$logo` in the constructor of the configuration. */
    private const int LOGO_ARGUMENT = 1;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::CONFIGURATION) || !$container->has('asset_mapper')) {
            return;
        }

        foreach ($container->getExtensionConfig('ux_showcase') as $config) {
            if (isset($config['logo'])) {
                return;
            }
        }

        $container->getDefinition(self::CONFIGURATION)->setArgument(
            self::LOGO_ARGUMENT,
            new Expression(\sprintf("service('asset_mapper').getPublicPath('%s')", self::LOGO)),
        );
    }
}
