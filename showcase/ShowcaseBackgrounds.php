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

/**
 * The preview backgrounds of the showcase: the ones that really apply on this theme.
 *
 * The showcase paints the chosen colour on the `<html>` element, and the `<body>` of the theme paints
 * its own page colour over it: a light, a white or a dark choice would change nothing on screen. The
 * only background to offer is then the page colour of the theme, `--color-lightest` of its
 * `variables.css`. Only a hexadecimal value is returned, a safe one to put in a style attribute.
 */
final class ShowcaseBackgrounds
{
    public const string THEME = 'theme';

    /**
     * @return array<string, array{color: string}> the list that replaces the default one, empty when the stylesheet has no page colour
     */
    public static function fromCss(string $css): array
    {
        // The last declaration wins, as in the cascade.
        if (0 === preg_match_all('/--color-lightest\s*:\s*(#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8}))\s*(?:;|\}|$)/m', $css, $matches)) {
            return [];
        }

        return [self::THEME => ['color' => end($matches[1])]];
    }
}
