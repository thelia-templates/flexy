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

use OpenStudio\UxShowcaseBundle\Preview\CheckRequestFactoryInterface;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Lang;

/**
 * `ux-showcase:check` renders every example through requests it creates itself, and the theme asks
 * them for what only Thelia's own have: a `Thelia\Core\HttpFoundation\Request` (`LangService::getLang()`)
 * and a Thelia session (`getLang()`, `setReturnToUrl()`). Every component that reads the language
 * fails on a plain Symfony request, though it renders in a browser.
 *
 * The requests of the shop are always Thelia's, so this changes nothing for a visitor.
 */
final readonly class TheliaCheckRequestFactory implements CheckRequestFactoryInterface
{
    public function createRequest(string $uri): SymfonyRequest
    {
        $request = Request::create($uri);

        $session = new Session(new MockArraySessionStorage());
        // What the language listener of the shop does on the first request of a visitor.
        $session->setLang(Lang::getDefaultLanguage());
        $request->setSession($session);

        return $request;
    }
}
