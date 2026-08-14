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

namespace FlexyBundle\Twig;

use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes `provide()` and `inject()` to the templates, and only where nothing else does.
 *
 * ux-twig-component ships both from 3.0 on, and that release requires PHP 8.4. Thelia
 * supports 8.3, where Composer resolves the 2.x line instead and the two functions are
 * missing: every compound component of the theme then fails to render. Declaring them
 * here keeps the theme working on both, without pinning the shop to one PHP version.
 *
 * On 3.x the package wins and this extension declares nothing, so there is one
 * implementation in play at any time, never two answering the same name.
 */
class ComponentContextExtension extends AbstractExtension
{
    public function __construct(private readonly ComponentContext $context)
    {
    }

    public function getFunctions(): array
    {
        if ($this->packageProvidesThem()) {
            return [];
        }

        return [
            new TwigFunction('provide', $this->context->provide(...)),
            new TwigFunction('inject', $this->context->inject(...)),
        ];
    }

    private function packageProvidesThem(): bool
    {
        if (!class_exists(InstalledVersions::class)) {
            return false;
        }

        return InstalledVersions::satisfies(new VersionParser(), 'symfony/ux-twig-component', '>=3.0');
    }
}
