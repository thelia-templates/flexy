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
 * The page background of the showcase: the `--color-lightest` of the theme's `variables.css`, the
 * colour the toolkit lays its pages on, so a preview melts into the page around it. Only a
 * hexadecimal value is returned, a safe one to put in a style attribute.
 */
final class ShowcasePageBackground
{
    public static function fromCss(string $css): ?string
    {
        // The last declaration wins, as in the cascade.
        if (0 === preg_match_all('/--color-lightest\s*:\s*(#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8}))\s*(?:;|\}|$)/m', $css, $matches)) {
            return null;
        }

        return end($matches[1]);
    }
}
