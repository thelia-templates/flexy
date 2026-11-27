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
     * The breakpoints of the stylesheet, narrowest first, as the toolkit's page of the tokens reads them.
     *
     * @return list<array{name: string, rem: float, px: float, label: string|null}>
     */
    public static function breakpointsFromCss(string $css): array
    {
        preg_match_all('/--breakpoint-([\w-]+):\s*([\d.]+)rem/', $css, $matches, \PREG_SET_ORDER);

        $breakpoints = [];
        foreach ($matches as $match) {
            $breakpoints[$match[1]] = (float) $match[2];
        }

        asort($breakpoints);

        return array_map(
            static fn (string $name, float $rem): array => [
                'name' => $name,
                'rem' => $rem,
                'px' => $rem * self::PIXELS_PER_REM,
                'label' => ToolkitController::LABELS[$name] ?? null,
            ],
            array_keys($breakpoints),
            array_values($breakpoints),
        );
    }

    /**
     * Responsive first, then one width per breakpoint, narrowest first, in pixels.
     *
     * @return array<string, int|null>
     */
    public static function fromCss(string $css): array
    {
        $viewports = ['responsive' => null];
        foreach (self::breakpointsFromCss($css) as $breakpoint) {
            $viewports[$breakpoint['label'] ?? $breakpoint['name']] = (int) round($breakpoint['px']);
        }

        return $viewports;
    }
}
