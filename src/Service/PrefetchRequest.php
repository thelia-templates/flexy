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

namespace FlexyBundle\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Whether a request is the browser or Turbo fetching a page ahead of a click.
 *
 * Turbo 8 prefetches the link under the pointer with `X-Sec-Purpose: prefetch`, the browsers' speculation rules with
 * `Sec-Purpose: prefetch`. A screen that changes the state of the checkout when it is shown must not do it for a page
 * the buyer has not opened.
 */
final class PrefetchRequest
{
    private const HEADERS = ['X-Sec-Purpose', 'Sec-Purpose'];

    public static function is(Request $request): bool
    {
        foreach (self::HEADERS as $header) {
            if (str_contains(strtolower((string) $request->headers->get($header)), 'prefetch')) {
                return true;
            }
        }

        return false;
    }
}
