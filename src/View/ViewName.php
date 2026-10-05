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

namespace FlexyBundle\View;

/**
 * The name a page goes by in the request's `_view` attribute.
 *
 * The core sets it for the pages it routes itself (`index`, `product`, `category`...). A page
 * a theme controller renders has none, so the controller derives it from the template it
 * renders: `checkout-cart.html.twig` is the `checkout-cart` view.
 */
final readonly class ViewName
{
    public static function fromTemplate(string $templateName): string
    {
        $viewName = preg_replace('/\.(html\.twig|twig|html)$/', '', trim($templateName)) ?? '';

        if ('' === $viewName) {
            throw new \InvalidArgumentException(\sprintf('No view name can be derived from the template "%s".', $templateName));
        }

        return $viewName;
    }
}
