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
 * The primary colour of the showcase: the `--color-dark` of the theme's `variables.css`, the colour
 * the theme picks for its focus ring, chosen for its contrast. Only a hexadecimal value is returned,
 * a safe one to put in a style attribute.
 */
final class ShowcasePrimaryColour
{
    public static function fromCss(string $css): ?string
    {
        // The last declaration wins, as in the cascade.
        if (0 === preg_match_all('/--color-dark\s*:\s*(#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8}))\s*(?:;|\}|$)/m', $css, $matches)) {
            return null;
        }

        return end($matches[1]);
    }
}
