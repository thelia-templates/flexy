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

use FlexyBundle\Search\ProductSearchEngineInterface;
use FlexyBundle\Search\ProductSearchEngineWithAlternativeTermsInterface;
use FlexyBundle\Search\VisibleProductIds;
use FlexyBundle\Service\ProductSearch;
use FlexyBundle\Service\ProductSort;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Search\Contract\SearchableDocumentProviderInterface;
use Thelia\Domain\Search\Contract\SearchEngineInterface;
use Thelia\Domain\Search\Contract\SearchScope;
use Thelia\Domain\Search\Contract\SearchTermExpanderInterface;
use Thelia\Domain\Search\Contract\SearchTermRewriterInterface;
use Thelia\Domain\Search\Document\SearchHit;
use Thelia\Domain\Search\Document\SearchQuery;
use Thelia\Domain\Search\Document\SearchResult;
use Thelia\Domain\Search\SearchDocumentProviders;
use Thelia\Domain\Search\SearchEngineRegistry;
use Thelia\Domain\Search\SearchService;

/**
 * Without a search engine the API's title filter answers; with one, the engine says which
 * products match and the theme keeps visibility, pagination and sort, in relevance order when
 * the visitor chose no sort.
 */
final class ProductSearchTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $apiCalls = [];

    /** @var list<array{string, string, int}> */
    private array $engineCalls = [];

    /** @var list<SearchQuery> */
    private array $coreQueries = [];

    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->apiCalls = [];
        $this->engineCalls = [];
        $this->coreQueries = [];
        $this->requestStack = new RequestStack();
    }

    public function testWithoutEngineTheApiTitleFilterAnswers(): void
    {
        $result = $this->productSearch(catalog: [3, 7])->search('  chaise ', 2, 10);

        self::assertCount(1, $this->apiCalls);
        self::assertSame('chaise', $this->apiCalls[0]['title']);
        self::assertTrue($this->apiCalls[0]['visible']);
        self::assertSame(2, $this->apiCalls[0]['page']);
        self::assertSame(10, $this->apiCalls[0]['itemsPerPage']);
        self::assertArrayNotHasKey('id', $this->apiCalls[0]);
        self::assertSame(2, $result['total']);
    }

    public function testWithoutEngineTheSynonymsOfTheTermAreLookedForInTheTitles(): void
    {
        if (!interface_exists(SearchTermExpanderInterface::class)) {
            self::markTestSkipped('The installed core has no search dictionary.');
        }

        $expander = $this->createStub(SearchTermExpanderInterface::class);
        $expander->method('expand')->willReturnCallback(static fn (string $term, ?string $locale): array => $term === 'chaise' && $locale === 'fr_FR' ? ['siège', 'tabouret'] : []);
        $coreSearch = new SearchService(new SearchEngineRegistry([]), new SearchDocumentProviders([]), [], [], new NullLogger(), [$expander]);

        $this->productSearch(catalog: [3, 7], coreSearch: $coreSearch)->search(' chaise ');
        $this->productSearch(catalog: [3, 7], coreSearch: $coreSearch)->search('table');

        self::assertSame(['chaise', 'siège', 'tabouret'], $this->apiCalls[0]['title']);
        self::assertSame('table', $this->apiCalls[1]['title']);
    }

    public function testWithoutEngineTheWildcardsOfTheTermAreSearchedLiterally(): void
    {
        $this->productSearch(catalog: [3, 7])->search('100%_coton\\');

        self::assertSame('100\\%\\_coton\\\\', $this->apiCalls[0]['title']);
    }

    public function testWithoutEngineTheWildcardsOfTheSynonymsAreSearchedLiterally(): void
    {
        if (!interface_exists(SearchTermExpanderInterface::class)) {
            self::markTestSkipped('The installed core has no search dictionary.');
        }

        $expander = $this->createStub(SearchTermExpanderInterface::class);
        $expander->method('expand')->willReturn(['50%']);
        $coreSearch = new SearchService(new SearchEngineRegistry([]), new SearchDocumentProviders([]), [], [], new NullLogger(), [$expander]);

        $this->productSearch(catalog: [3, 7], coreSearch: $coreSearch)->search('half_price');

        self::assertSame(['half\\_price', '50\\%'], $this->apiCalls[0]['title']);
    }

    public function testWithoutEngineTheTitlesAreSearchedForTheRewrittenTermAndItsSynonyms(): void
    {
        if (!interface_exists(SearchTermExpanderInterface::class)) {
            self::markTestSkipped('The installed core has no search dictionary.');
        }

        $rewriter = $this->createStub(SearchTermRewriterInterface::class);
        $rewriter->method('rewrite')->willReturnCallback(static fn (string $term): string => $term === 'chaize' ? 'chaise' : $term);
        $expander = $this->createStub(SearchTermExpanderInterface::class);
        $expander->method('expand')->willReturnCallback(static fn (string $term): array => $term === 'chaise' ? ['siège'] : []);
        $coreSearch = new SearchService(new SearchEngineRegistry([]), new SearchDocumentProviders([]), [$rewriter], [], new NullLogger(), [$expander]);

        $this->productSearch(catalog: [3, 7], coreSearch: $coreSearch)->search('chaize');

        self::assertSame(['chaise', 'siège'], $this->apiCalls[0]['title']);
    }

    public function testATermTheRewritingLeavesEmptyFindsNothingWithoutAskingTheCatalogue(): void
    {
        if (!interface_exists(SearchTermRewriterInterface::class)) {
            self::markTestSkipped('The installed core has no search dictionary.');
        }

        $rewriter = $this->createStub(SearchTermRewriterInterface::class);
        $rewriter->method('rewrite')->willReturnCallback(static fn (string $term): string => $term === 'le' ? '' : $term);
        $coreSearch = new SearchService(new SearchEngineRegistry([]), new SearchDocumentProviders([]), [$rewriter], [], new NullLogger(), []);

        $result = $this->productSearch(catalog: [3, 7], coreSearch: $coreSearch)->search('le');

        self::assertSame([], $result['products']);
        self::assertSame(0, $result['total']);
        self::assertSame([], $this->apiCalls);
    }

    public function testAnEngineThatTakesAlternativeTermsReceivesTheSynonymsOfTheCore(): void
    {
        if (!interface_exists(SearchTermExpanderInterface::class)) {
            self::markTestSkipped('The installed core has no search dictionary.');
        }

        $expander = $this->createStub(SearchTermExpanderInterface::class);
        $expander->method('expand')->willReturnCallback(static fn (string $term, ?string $locale): array => $term === 'tel' && $locale === 'fr_FR' ? ['téléphone'] : []);
        $coreSearch = new SearchService(new SearchEngineRegistry([]), new SearchDocumentProviders([]), [], [], new NullLogger(), [$expander]);
        $engine = new class implements ProductSearchEngineWithAlternativeTermsInterface {
            /** @var list<array{string, list<string>}> */
            public array $calls = [];

            public function productIds(string $term, string $locale, int $limit): array
            {
                throw new \LogicException('The engine is asked with its alternative terms.');
            }

            public function productIdsMatchingAnyTerm(string $term, array $alternativeTerms, string $locale, int $limit): array
            {
                $this->calls[] = [$term, $alternativeTerms];

                return [7];
            }
        };

        $result = $this->productSearch(catalog: [3, 7], coreSearch: $coreSearch, engine: $engine)->search(' tel ');

        self::assertSame([['tel', ['téléphone']]], $engine->calls);
        self::assertSame([7], $this->ids($result));
    }

    public function testAnEngineWithoutAlternativeTermsReceivesTheRewrittenTerm(): void
    {
        if (!interface_exists(SearchTermRewriterInterface::class)) {
            self::markTestSkipped('The installed core has no search dictionary.');
        }

        $rewriter = $this->createStub(SearchTermRewriterInterface::class);
        $rewriter->method('rewrite')->willReturnCallback(static fn (string $term): string => $term === 'chaize' ? 'chaise' : $term);
        $coreSearch = new SearchService(new SearchEngineRegistry([]), new SearchDocumentProviders([]), [$rewriter], [], new NullLogger(), []);

        $result = $this->productSearch(catalog: [4, 12], engineIds: [12], coreSearch: $coreSearch)->search('chaize');

        self::assertSame([['chaise', 'fr_FR', ProductSearch::ENGINE_LIMIT]], $this->engineCalls);
        self::assertSame([12], $this->ids($result));
    }

    public function testWithAnEngineAndNoSortTheRelevanceOrderIsKept(): void
    {
        $result = $this->productSearch(catalog: [4, 12, 27], engineIds: [12, 4, 27])->search('chaise');

        self::assertSame([['chaise', 'fr_FR', ProductSearch::ENGINE_LIMIT]], $this->engineCalls);
        self::assertCount(1, $this->apiCalls);
        self::assertSame([12, 4, 27], $this->apiCalls[0]['id']);
        self::assertTrue($this->apiCalls[0]['visible']);
        self::assertArrayNotHasKey('title', $this->apiCalls[0]);
        self::assertSame([12, 4, 27], $this->ids($result));
        self::assertSame(3, $result['total']);
    }

    public function testWithAnEngineThePagesAreSlicesOfTheRelevanceOrder(): void
    {
        $search = $this->productSearch(catalog: [4, 12, 27, 30, 31], engineIds: [31, 12, 4, 30, 27]);

        self::assertSame([31, 12], $this->ids($search->search('chaise', 1, 2)));
        self::assertSame([4, 30], $this->ids($search->search('chaise', 2, 2)));

        $last = $search->search('chaise', 3, 2);
        self::assertSame([27], $this->ids($last));
        self::assertSame(5, $last['total']);

        $beyond = $search->search('chaise', 4, 2);
        self::assertSame([], $beyond['products']);
        self::assertSame(5, $beyond['total']);
    }

    public function testWithAnEngineTheInvisibleMatchesAreLeftOutOfPagesAndTotal(): void
    {
        $result = $this->productSearch(catalog: [4, 12, 27], engineIds: [12, 99, 4, 27], visible: [4, 12, 27])->search('chaise', 1, 2);

        self::assertSame([12, 4], $this->ids($result));
        self::assertSame(3, $result['total']);
    }

    public function testWithAnEngineAChosenSortIsAppliedByTheApiToTheMatches(): void
    {
        $result = $this->productSearch(catalog: [4, 12, 27], engineIds: [12, 4, 27])->search('chaise', 1, 30, 'asc');

        self::assertSame([12, 4, 27], $this->apiCalls[0]['id']);
        self::assertSame('asc', $this->apiCalls[0]['untaxed_price_order']);
        self::assertSame(1, $this->apiCalls[0]['page']);
        self::assertSame(30, $this->apiCalls[0]['itemsPerPage']);
        // the API's own order is kept
        self::assertSame([4, 12, 27], $this->ids($result));
    }

    public function testAnEngineAnsweringNothingMeansNoResultWithoutFallback(): void
    {
        $result = $this->productSearch(catalog: [4], engineIds: [])->search('chaise');

        self::assertSame([], $this->apiCalls);
        self::assertSame(['products' => [], 'total' => 0], $result);
    }

    public function testABlankTermFindsNothingAndAsksNobody(): void
    {
        $result = $this->productSearch(catalog: [4], engineIds: [4])->search('   ');

        self::assertSame([], $this->apiCalls);
        self::assertSame([], $this->engineCalls);
        self::assertSame(['products' => [], 'total' => 0], $result);
    }

    public function testTheFirstEngineOfTheIteratorAnswers(): void
    {
        $second = $this->createMock(ProductSearchEngineInterface::class);
        $second->expects(self::never())->method('productIds');

        $search = new ProductSearch(
            $this->dataAccess([4, 12]),
            new ProductSort(),
            new EventDispatcher(),
            $this->langService(),
            [$this->engine([12]), $second],
        );

        self::assertSame([12], $this->ids($search->search('chaise')));
    }

    public function testWithoutThemeEngineTheCoreEngineAnswersWithTheRewrittenTerm(): void
    {
        $search = $this->productSearch(catalog: [4, 12, 27], coreSearch: $this->coreSearch([12, 4], rewriteTo: 'chair'));

        $result = $search->search('chaise');

        self::assertSame('chair', $this->coreQueries[0]->term);
        self::assertSame('fr_FR', $this->coreQueries[0]->locale);
        self::assertSame(['product'], $this->coreQueries[0]->families);
        self::assertTrue($this->coreQueries[0]->visibleOnly);
        self::assertSame([12, 4], $this->ids($result));
        self::assertArrayNotHasKey('title', $this->apiCalls[0]);
    }

    public function testTheCoreEngineIsReadPageAfterPageUpToTheEngineLimit(): void
    {
        $matches = range(1, 250);
        $search = $this->productSearch(catalog: $matches, coreSearch: $this->coreSearch($matches));

        $result = $search->search('chaise', 1, 10);

        self::assertSame([0, 100, 200], array_map(static fn (SearchQuery $query): int => $query->offset, $this->coreQueries));
        self::assertSame(250, $result['total']);
        self::assertSame(range(1, 10), $this->ids($result));
    }

    public function testAHiddenMatchOfTheIndexDoesNotStopTheReadingOfTheNextPages(): void
    {
        $matches = range(1, 250);
        $search = $this->productSearch(catalog: $matches, coreSearch: $this->coreSearch($matches, hidden: [5]));

        $result = $search->search('chaise', 1, 10);

        self::assertSame([0, 100, 200], array_map(static fn (SearchQuery $query): int => $query->offset, $this->coreQueries));
        self::assertSame(249, $result['total']);
    }

    public function testAFirstPageShortenedBelowItsSizeByHiddenMatchesDoesNotStopTheReading(): void
    {
        $matches = range(1, 150);
        $search = $this->productSearch(catalog: $matches, coreSearch: $this->coreSearch($matches, hidden: range(1, 60)));

        $result = $search->search('chaise', 1, 10);

        self::assertSame([0, 100], array_map(static fn (SearchQuery $query): int => $query->offset, $this->coreQueries));
        self::assertSame(90, $result['total']);
        self::assertSame(range(61, 70), $this->ids($result));
    }

    public function testAPageOfTheIndexEntirelyHiddenDoesNotStopTheReading(): void
    {
        $matches = range(1, 250);
        $search = $this->productSearch(catalog: $matches, coreSearch: $this->coreSearch($matches, hidden: range(1, 100)));

        $result = $search->search('chaise', 1, 10);

        self::assertSame([0, 100, 200], array_map(static fn (SearchQuery $query): int => $query->offset, $this->coreQueries));
        self::assertSame(150, $result['total']);
    }

    public function testAnUnavailableCoreEngineLetsTheDatabaseAnswer(): void
    {
        $search = $this->productSearch(catalog: [3, 7], coreSearch: $this->coreSearch([3], available: false));

        $result = $search->search('chaise');

        self::assertSame('chaise', $this->apiCalls[0]['title']);
        self::assertSame(2, $result['total']);
    }

    public function testAThemeEngineIsPreferredToTheCoreEngine(): void
    {
        $search = $this->productSearch(catalog: [4, 12], engineIds: [12], coreSearch: $this->coreSearch([4]));

        self::assertSame([12], $this->ids($search->search('chaise')));
        self::assertSame([], $this->coreQueries);
    }

    public function testPastTheRateLimitTheDatabaseAnswersInsteadOfTheEngine(): void
    {
        $search = $this->productSearch(catalog: [3, 7], engineIds: [7], limit: 1);

        $search->search('chaise');
        $this->nextRequest();
        $this->apiCalls = [];
        $result = $search->search('chaise');

        self::assertCount(1, $this->engineCalls);
        self::assertSame('chaise', $this->apiCalls[0]['title']);
        self::assertSame(2, $result['total']);
    }

    public function testTheResultsPageSpendsOneTokenForItsCountAndItsList(): void
    {
        $limiter = $this->limiter(2);
        $search = $this->productSearch(catalog: [3, 7], engineIds: [7], limiter: $limiter);

        $search->countSubmitted('chaise');
        $search->search('chaise');

        self::assertCount(2, $this->engineCalls);
        self::assertTrue($limiter->create('203.0.113.9')->consume()->isAccepted());
    }

    public function testARequestRefusedByTheRateLimitStaysOnTheDatabaseForItsWholeLength(): void
    {
        $limiter = $this->limiter(1);
        $search = $this->productSearch(catalog: [3, 7], engineIds: [7], limiter: $limiter);

        $search->search('chaise');
        $this->nextRequest();
        $search->countSubmitted('chaise');
        $limiter->create('203.0.113.9')->reset();
        $search->search('chaise');

        self::assertCount(1, $this->engineCalls);
    }

    public function testASearchWithoutEngineSpendsNoRateLimit(): void
    {
        $limiter = $this->limiter(1);
        $search = $this->productSearch(catalog: [3, 7], limiter: $limiter);

        $search->search('chaise');

        self::assertTrue($limiter->create('203.0.113.9')->consume()->isAccepted());
    }

    /**
     * @param list<int>      $catalog   ids the API knows, which it returns in ascending id order
     * @param list<int>|null $engineIds null for no engine
     * @param list<int>|null $visible   null when every id is visible
     */
    private function productSearch(array $catalog, ?array $engineIds = null, ?array $visible = null, ?SearchService $coreSearch = null, ?int $limit = null, ?RateLimiterFactory $limiter = null, ?ProductSearchEngineInterface $engine = null): ProductSearch
    {
        $this->requestStack->push(Request::create('/search', server: ['REMOTE_ADDR' => '203.0.113.9']));

        $visibleProductIds = $this->createStub(VisibleProductIds::class);
        $visibleProductIds->method('filter')->willReturnCallback(
            static fn (array $ids): array => $visible === null ? $ids : array_values(array_filter($ids, static fn (int $id): bool => \in_array($id, $visible, true))),
        );

        return new ProductSearch(
            $this->dataAccess($catalog),
            new ProductSort(),
            new EventDispatcher(),
            $this->langService(),
            $engine !== null ? [$engine] : ($engineIds === null ? [] : [$this->engine($engineIds)]),
            $visibleProductIds,
            $coreSearch,
            $limiter ?? $this->limiter($limit ?? 1000),
            $this->requestStack,
        );
    }

    private function nextRequest(): void
    {
        $this->requestStack->pop();
        $this->requestStack->push(Request::create('/search', server: ['REMOTE_ADDR' => '203.0.113.9']));
    }

    private function limiter(int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => 'search', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'], new InMemoryStorage());
    }

    /**
     * @param list<int> $matches
     * @param list<int> $hidden
     */
    private function coreSearch(array $matches, ?string $rewriteTo = null, bool $available = true, array $hidden = []): SearchService
    {
        $record = function (SearchQuery $query): void {
            $this->coreQueries[] = $query;
        };

        $engine = new class($matches, $available, $record) implements SearchEngineInterface {
            /**
             * @param list<int> $matches
     * @param list<int> $hidden
             */
            public function __construct(private array $matches, private bool $available, private \Closure $record)
            {
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function index(array $documents): void
            {
            }

            public function remove(string $family, array $ids, ?string $locale): void
            {
            }

            public function purge(string $family): void
            {
            }

            public function search(SearchQuery $query): SearchResult
            {
                ($this->record)($query);
                $page = \array_slice($this->matches, $query->offset, $query->limit);

                return new SearchResult(array_map(static fn (int $id): SearchHit => new SearchHit('product', $id, 1.0), $page), \count($this->matches));
            }
        };

        $provider = $this->createStub(SearchableDocumentProviderInterface::class);
        $provider->method('family')->willReturn('product');
        $provider->method('scope')->willReturn(SearchScope::Shop);
        $provider->method('visibleIds')->willReturnCallback(static fn (array $ids): array => array_values(array_diff($ids, $hidden)));

        $rewriters = [];

        if ($rewriteTo !== null) {
            $rewriter = $this->createStub(SearchTermRewriterInterface::class);
            $rewriter->method('rewrite')->willReturn($rewriteTo);
            $rewriters[] = $rewriter;
        }

        return new SearchService(new SearchEngineRegistry([$engine]), new SearchDocumentProviders([$provider]), $rewriters, [], new NullLogger());
    }

    /**
     * @param list<int> $ids
     */
    private function engine(array $ids): ProductSearchEngineInterface
    {
        $engine = $this->createStub(ProductSearchEngineInterface::class);
        $engine->method('productIds')->willReturnCallback(function (string $term, string $locale, int $limit) use ($ids): array {
            $this->engineCalls[] = [$term, $locale, $limit];

            return $ids;
        });

        return $engine;
    }

    /**
     * @param list<int> $catalog
     */
    private function dataAccess(array $catalog): DataAccessService
    {
        $dataAccess = $this->createStub(DataAccessService::class);
        $dataAccess->method('resources')->willReturnCallback(function (string $path, array $parameters) use ($catalog): array {
            $this->apiCalls[] = $parameters;

            $matches = isset($parameters['id'])
                ? array_values(array_intersect($catalog, $parameters['id']))
                : $catalog;
            $page = \array_slice($matches, ($parameters['page'] - 1) * $parameters['itemsPerPage'], $parameters['itemsPerPage']);

            return [
                'hydra:member' => array_map(static fn (int $id): array => ['id' => $id], $page),
                // JSON-LD decoding hands the total back as a float.
                'hydra:totalItems' => (float) \count($matches),
            ];
        });

        return $dataAccess;
    }

    private function langService(): LangService
    {
        $langService = $this->createStub(LangService::class);
        $langService->method('getLocale')->willReturn('fr_FR');

        return $langService;
    }

    /**
     * @param array{products: list<\FlexyBundle\DTO\ProductDTO>, total: int} $result
     *
     * @return list<int>
     */
    private function ids(array $result): array
    {
        return array_map(static fn ($product): int => $product->id, $result['products']);
    }
}
