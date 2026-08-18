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

use Propel\Runtime\Exception\PropelException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Routing\Rewriting\Exception\UrlRewritingException;
use Thelia\Model\ConfigQuery;
use Thelia\Tools\URL;

#[AsTwigComponent]
class Base
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $langs = null;

    /** @var array<string, string>|null */
    private ?array $switchUrls = null;

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
     * Where each active language sends the visitor, keyed by locale.
     *
     * Switching language must keep the visitor on the page being read, so the link
     * points at the translation of that page whenever one can be named here. When it
     * cannot - rewriting disabled, page without a rewritten url, language without a
     * translation of it, one domain per language - the link keeps the current path
     * and carries ?lang=, which the core turns into the right page on the next
     * request: RewritingRouter::maybeRedirectForRequestedLocale() for a rewritten
     * page, LangService::resolveFrontLanguageFromRequest() otherwise, including the
     * redirect to the domain of the language.
     *
     * @return array<string, string>
     */
    public function getSwitchUrls(): array
    {
        if (null !== $this->switchUrls) {
            return $this->switchUrls;
        }

        $request = $this->requestStack->getMainRequest();

        if (!$request instanceof Request) {
            return $this->switchUrls = [];
        }

        $currentPage = $this->resolveCurrentPage($request);
        $currentPath = $request->getBaseUrl().$request->getPathInfo();
        $query = $this->requestedQuery($request);

        $switchUrls = [];

        foreach ($this->getLangs() as $lang) {
            $locale = $lang['locale'] ?? null;

            if (!\is_string($locale) || '' === $locale) {
                continue;
            }

            $translatedUrl = null === $currentPage ? null : $this->translatedUrl($currentPage, $locale);

            $switchUrls[$locale] = null === $translatedUrl
                ? $this->withQuery($currentPath, $query + ['lang' => $locale])
                : $this->withQuery($translatedUrl, $query);
        }

        return $this->switchUrls = $switchUrls;
    }

    /**
     * View and id of the page being read, when a rewritten url serves it.
     *
     * One domain per language is left out on purpose: the domain of a language is not
     * exposed to the front, so the target url cannot be named here and the core
     * redirect stays in charge of it.
     *
     * @return array{view: string, viewId: mixed}|null
     */
    private function resolveCurrentPage(Request $request): ?array
    {
        try {
            if (!ConfigQuery::isRewritingEnable() || ConfigQuery::isMultiDomainActivated()) {
                return null;
            }

            $resolver = URL::getInstance()->resolve($request->getPathInfo());
        } catch (UrlRewritingException|PropelException|\RuntimeException) {
            return null;
        }

        if (!\is_string($resolver->view) || '' === $resolver->view) {
            return null;
        }

        return ['view' => $resolver->view, 'viewId' => $resolver->viewId];
    }

    /**
     * @param array{view: string, viewId: mixed} $currentPage
     */
    private function translatedUrl(array $currentPage, string $locale): ?string
    {
        try {
            return URL::getInstance()
                ->retrieve($currentPage['view'], $currentPage['viewId'], $locale)
                ->rewrittenUrl;
        } catch (PropelException|\RuntimeException) {
            return null;
        }
    }

    /**
     * The query string as the visitor asked for it. The request query bag cannot be
     * used: RewritingRouter feeds the resolved url back into it - the view id and the
     * rewriting arguments - and those internal parameters have no place in a link.
     *
     * @return array<string, mixed>
     */
    private function requestedQuery(Request $request): array
    {
        parse_str((string) $request->getQueryString(), $query);

        unset($query['lang'], $query['locale']);

        return $query;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function withQuery(string $url, array $query): string
    {
        return [] === $query ? $url : $url.'?'.http_build_query($query);
    }
}
