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

namespace FlexyBundle\Tests\Unit\EventListener;

use FlexyBundle\EventListener\SearchRedirectSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\ViewCheckEvent;
use Thelia\Core\HttpKernel\Exception\RedirectException;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Search\Contract\SearchRedirectResolverInterface;
use Thelia\Domain\Search\SearchDocumentProviders;
use Thelia\Domain\Search\SearchEngineRegistry;
use Thelia\Domain\Search\SearchService;

final class SearchRedirectSubscriberTest extends TestCase
{
    public function testASubmittedSearchWhoseTermIsMappedLandsOnItsPage(): void
    {
        try {
            $this->subscriber('/search?query=+soldes+')->onViewCheck(new ViewCheckEvent('search', null));
            self::fail('No redirection.');
        } catch (RedirectException $redirect) {
            self::assertSame('/sale/3', $redirect->getUrl());
            self::assertSame(302, $redirect->getStatusCode());
        }
    }

    public function testTheNextPagesOfASearchAreNotRedirected(): void
    {
        $this->subscriber('/search?query=soldes&page=2')->onViewCheck(new ViewCheckEvent('search', null));

        $this->addToAssertionCount(1);
    }

    public function testASortedSearchIsNotRedirected(): void
    {
        $this->subscriber('/search?query=soldes&sort=asc')->onViewCheck(new ViewCheckEvent('search', null));

        $this->addToAssertionCount(1);
    }

    public function testAnUnmappedTermStaysOnTheResults(): void
    {
        $this->subscriber('/search?query=chaise')->onViewCheck(new ViewCheckEvent('search', null));

        $this->addToAssertionCount(1);
    }

    public function testAnotherViewIsNotRedirected(): void
    {
        $this->subscriber('/category?query=soldes')->onViewCheck(new ViewCheckEvent('category', null));

        $this->addToAssertionCount(1);
    }

    private function subscriber(string $uri): SearchRedirectSubscriber
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create($uri));

        $resolver = $this->createStub(SearchRedirectResolverInterface::class);
        $resolver->method('redirectFor')->willReturnCallback(
            static fn (string $term, ?string $locale): ?string => $term === 'soldes' && $locale === 'fr_FR' ? '/sale/3' : null,
        );

        $langService = $this->createStub(LangService::class);
        $langService->method('getLocale')->willReturn('fr_FR');

        return new SearchRedirectSubscriber(
            $requestStack,
            $langService,
            new SearchService(new SearchEngineRegistry([]), new SearchDocumentProviders([]), [], [$resolver], new NullLogger()),
        );
    }
}
