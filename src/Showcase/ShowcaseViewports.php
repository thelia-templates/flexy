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

use FlexyBundle\Controller\ToolkitController;

/**
 * The preview widths of the showcase, read off the breakpoints of the theme's `variables.css`: the
 * ones the toolkit previews at, in the same words.
 */
final class ShowcaseViewports
{
    private const int PIXELS_PER_REM = 16;

    /**
     * Responsive first, then one width per breakpoint, narrowest first, in pixels.
     *
     * @return array<string, int|null>
     */
    public static function fromCss(string $css): array
    {
        preg_match_all('/--breakpoint-([\w-]+):\s*([\d.]+)rem/', $css, $matches, \PREG_SET_ORDER);

        $breakpoints = [];
        foreach ($matches as $match) {
            $breakpoints[$match[1]] = (float) $match[2];
        }

        asort($breakpoints);

        $viewports = ['responsive' => null];
        foreach ($breakpoints as $name => $rem) {
            $viewports[ToolkitController::LABELS[$name] ?? $name] = (int) round($rem * self::PIXELS_PER_REM);
        }

        return $viewports;
    }
}
