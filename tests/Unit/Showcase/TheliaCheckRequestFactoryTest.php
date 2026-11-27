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

namespace FlexyBundle\Tests\Unit\Showcase;

use FlexyBundle\Showcase\TheliaCheckRequestFactory;
use OpenStudio\UxShowcaseBundle\Preview\CheckRequestFactoryInterface;
use PHPUnit\Framework\TestCase;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Lang;

final class TheliaCheckRequestFactoryTest extends TestCase
{
    public function testItIsTheFactoryOfTheCheckCommand(): void
    {
        self::assertInstanceOf(CheckRequestFactoryInterface::class, new TheliaCheckRequestFactory());
    }

    public function testTheRequestIsTheOneOfThelia(): void
    {
        $request = (new TheliaCheckRequestFactory())->createRequest('/_showcase/flexy/Forms:Address:Base/forms-address/preview');

        self::assertInstanceOf(Request::class, $request);
        self::assertSame('/_showcase/flexy/Forms:Address:Base/forms-address/preview', $request->getPathInfo());
    }

    public function testTheSessionIsTheOneOfThelia(): void
    {
        $request = (new TheliaCheckRequestFactory())->createRequest('/');

        self::assertTrue($request->hasSession());
        self::assertInstanceOf(Session::class, $request->getSession());
    }

    public function testTheLanguageIsTheDefaultOne(): void
    {
        $default = new Lang();
        $default->setId(7)->setCode('xx')->setLocale('xx_XX');
        $this->forceDefaultLanguage($default);

        $session = (new TheliaCheckRequestFactory())->createRequest('/')->getSession();

        self::assertInstanceOf(Session::class, $session);
        // getLang() would fall back to the default language by itself: read what was stored.
        self::assertSame($default, $session->get('thelia.current.lang'));
    }

    public function testEveryRequestGetsItsOwnSession(): void
    {
        $factory = new TheliaCheckRequestFactory();

        self::assertNotSame($factory->createRequest('/')->getSession(), $factory->createRequest('/')->getSession());
    }

    protected function setUp(): void
    {
        if (!interface_exists(CheckRequestFactoryInterface::class)) {
            self::markTestSkipped('openstudio/ux-showcase-bundle is a suggestion of the theme and is not installed.');
        }

        // The unit suite has no database: the default language is the one the test hands over.
        $default = new Lang();
        $default->setId(1)->setCode('en')->setLocale('en_US');
        $this->forceDefaultLanguage($default);
    }

    protected function tearDown(): void
    {
        Lang::resetDefaultLanguageCache();
    }

    private function forceDefaultLanguage(Lang $lang): void
    {
        $property = new \ReflectionProperty(Lang::class, 'defaultLanguage');
        $property->setValue(null, $lang);
    }
}
