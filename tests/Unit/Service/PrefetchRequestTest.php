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

namespace FlexyBundle\Tests\Unit\Service;

use FlexyBundle\Service\PrefetchRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PrefetchRequestTest extends TestCase
{
    public function testTheTurboAndTheBrowserPrefetchAreRecognised(): void
    {
        foreach (['X-Sec-Purpose' => 'prefetch', 'Sec-Purpose' => 'prefetch;anonymous-client-ip', 'sec-purpose' => 'Prefetch'] as $header => $value) {
            $request = Request::create('/checkout/cart');
            $request->headers->set($header, $value);

            self::assertTrue(PrefetchRequest::is($request), $header);
        }
    }

    public function testAVisitIsNotAPrefetch(): void
    {
        self::assertFalse(PrefetchRequest::is(Request::create('/checkout/cart')));

        $request = Request::create('/checkout/cart');
        $request->headers->set('Sec-Fetch-Dest', 'document');
        self::assertFalse(PrefetchRequest::is($request));
    }
}
