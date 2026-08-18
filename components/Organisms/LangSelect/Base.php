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

namespace FlexyBundle\Components\Organisms\LangSelect;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Core\HttpFoundation\Session\Session;

#[AsTwigComponent]
class Base
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $langs = null;

    public function __construct(
        private readonly DataAccessService $dataAccessService,
        private readonly Session $session,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getLangs(): array
    {
        return $this->langs ??= $this->dataAccessService->resources('/api/front/languages', [
            'active' => true,
        ]) ?? [];
    }

    public function getCurrentLang(): ?array
    {
        $langs = $this->getLangs();

        if (\count($langs) <= 1) {
            return null;
        }

        $currentId = $this->session->getLang()?->getId();

        foreach ($langs as $lang) {
            if ((int) $lang['id'] === $currentId) {
                return $lang;
            }
        }

        return null;
    }

    /**
     * Where each active language sends the visitor, keyed by locale: the page being
     * read, asked for in that language. The core takes it from there, and keeps the
     * visitor in place - RewritingRouter redirects a rewritten url to its translation,
     * LangService switches the language of any other page and handles the redirect to
     * the domain of the language.
     *
     * The query string is read from the request and not from app.request.query, which
     * RewritingRouter::applyRewritingAttributes() fills with the view id and with the
     * parameters encoded in the rewritten url by the time a template renders. Those are
     * internal to the rewriting and have no business being written back into a link.
     *
     * @return array<string, string>
     */
    public function getSwitchUrls(): array
    {
        $request = $this->requestStack->getMainRequest();

        if (!$request instanceof Request) {
            return [];
        }

        parse_str((string) $request->getQueryString(), $query);
        unset($query['lang'], $query['locale']);

        $path = $request->getBaseUrl().$request->getPathInfo();
        $switchUrls = [];

        foreach ($this->getLangs() as $lang) {
            $locale = $lang['locale'] ?? null;

            if (\is_string($locale) && '' !== $locale) {
                $switchUrls[$locale] = $path.'?'.http_build_query($query + ['lang' => $locale]);
            }
        }

        return $switchUrls;
    }
}
