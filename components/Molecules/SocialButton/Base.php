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

namespace FlexyBundle\Components\Molecules\SocialButton;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Twig\Markup;

#[AsTwigComponent]
class Base
{
    public string $provider;
    public string $href;
    public ?string $label = null;
    // The provider's official logo, supplied ALREADY SANITIZED by the caller (the
    // SocialLogin module sanitizes what the merchant pasted in the back office before
    // handing it over). This component prints it as-is and never re-sanitizes, so the type
    // is the guard rail: Markup, not string, so a caller passing raw markup fails when the
    // component is mounted rather than reaching `|raw`. Null falls back to the theme's
    // built-in brand mark.
    public ?Markup $trustedLogoSvg = null;
}
