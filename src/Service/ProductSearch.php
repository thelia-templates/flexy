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

use FlexyBundle\DTO\ProductDTO;
use FlexyBundle\Search\ProductSearchEngineInterface;
use FlexyBundle\Search\ProductSearchEngineWithAlternativeTermsInterface;
use FlexyBundle\Search\VisibleProductIds;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Core\Event\Product\ProductSearchedEvent;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Search\Document\SearchQuery;
use Thelia\Domain\Search\Provider\ProductDocumentProvider;
use Thelia\Domain\Search\SearchService;

/**
 * Single entry point for product search: the search page, the listing and the suggestions all
 * go through it.
 *
 * Who finds the products is replaceable. A module registers a
 * FlexyBundle\Search\ProductSearchEngineInterface service (TntSearch does) and the one with the
 * highest tag priority answers which products match; the theme keeps what the visitor sees —
 * visibility, pagination and sort — and reads the products through the API as anywhere else:
 *
 * - no sort chosen: the engine's relevance order, paginated over the visible matches;
 * - a sort chosen: the API sorts and paginates the matches like any listing.
 *
 * An engine answering nothing means nothing matched: the theme does not second-guess it with
 * its own search. A module that logs its own searches would log each one twice, once itself and
 * once through the ProductSearchedEvent countSubmitted() dispatches.
 *
 * Without an engine, the API's `title` filter answers, and its limits show: it matches on word
 * starts only ("Claire" misses "Marie-Claire"), nothing but titles is searchable, and the i18n
 * filter has no locale fallback — a locale without translations returns nothing even though
 * pages show fallback titles.
 */
final readonly class ProductSearch
{
    /**
     * The most matches an engine is asked for. Past a thousand, nobody pages that far, and the
     * ids travel in the API query.
     */
    public const ENGINE_LIMIT = 1000;

    private const ENGINE_ALLOWED_ATTRIBUTE = '_flexy_product_search_engine_allowed';

    private ?ProductSearchEngineInterface $engine;

    /**
     * @param iterable<ProductSearchEngineInterface> $engines highest priority first
     */
    public function __construct(
        private DataAccessService $dataAccessService,
        private ProductSort $productSort,
        private EventDispatcherInterface $dispatcher,
        private LangService $langService,
        #[AutowireIterator('flexy.product_search_engine')]
        iterable $engines = [],
        private ?VisibleProductIds $visibleProductIds = null,
        private ?SearchService $coreSearch = null,
        #[Autowire(service: 'limiter.flexy_product_search')]
        private ?RateLimiterFactoryInterface $searchLimiter = null,
        private ?RequestStack $requestStack = null,
        private ?LoggerInterface $logger = null,
    ) {
        $engine = null;

        foreach ($engines as $engine) {
            break;
        }

        $this->engine = $engine;
    }

    /**
     * A blank term matches nothing: the API would drop the filter and return the whole catalogue.
     *
     * @return array{products: list<ProductDTO>, total: int}
     */
    public function search(string $term, int $page = 1, int $itemsPerPage = 30, ?string $sort = null): array
    {
        if (trim($term) === '') {
            return ['products' => [], 'total' => 0];
        }

        $term = trim($term);
        $locale = (string) $this->langService->getLocale();
        $terms = $this->termsOf($term, $locale);

        if ($terms === []) {
            return ['products' => [], 'total' => 0];
        }

        $matches = $this->engineMatches($term, $terms, $locale);

        if ($matches !== null) {
            return $this->searchWithEngine($matches, max(1, $page), $itemsPerPage, $sort);
        }

        return $this->fetch($this->parameters(['title' => $this->titleFilter($terms)], $page, $itemsPerPage, $sort));
    }

    public function count(string $term): int
    {
        return $this->search($term, itemsPerPage: 1)['total'];
    }

    /**
     * The count of a search the shopper submitted, told to the modules once with
     * ProductSearchedEvent so a search log (TntSearch) records the visitors' searches. The
     * suggestions shown while typing go through search() and are not submitted searches.
     *
     * A core older than the event gets the count alone.
     */
    public function countSubmitted(string $term): int
    {
        $total = $this->count($term);

        if (trim($term) !== '' && class_exists(ProductSearchedEvent::class)) {
            $this->dispatcher->dispatch(new ProductSearchedEvent(trim($term), (string) $this->langService->getLocale(), $total));
        }

        return $total;
    }

    /**
     * @param non-empty-list<string> $terms
     *
     * @return list<int>|null
     */
    private function engineMatches(string $term, array $terms, string $locale): ?array
    {
        $coreEngine = $this->engine === null && $this->coreSearch?->hasEngine() === true;

        if ($this->engine === null && !$coreEngine) {
            return null;
        }

        if (!$this->engineAllowed()) {
            return null;
        }

        if ($this->engine instanceof ProductSearchEngineWithAlternativeTermsInterface) {
            return array_map('intval', $this->engine->productIdsMatchingAnyTerm($terms[0], \array_slice($terms, 1), $locale, self::ENGINE_LIMIT));
        }

        if ($this->engine !== null) {
            return array_map('intval', $this->engine->productIds($terms[0], $locale, self::ENGINE_LIMIT));
        }

        return $this->coreEngineMatches($term, $locale);
    }

    /**
     * @return list<int>|null
     */
    private function coreEngineMatches(string $term, string $locale): ?array
    {
        \assert($this->coreSearch !== null);
        $ids = [];

        for ($offset = 0; $offset < self::ENGINE_LIMIT; $offset += SearchQuery::MAX_LIMIT) {
            $result = $this->coreSearch->search(new SearchQuery(
                $term,
                $locale,
                families: [ProductDocumentProvider::FAMILY],
                limit: min(SearchQuery::MAX_LIMIT, self::ENGINE_LIMIT - $offset),
                offset: $offset,
            ));

            if ($result === null) {
                return null;
            }

            foreach ($result->hits as $hit) {
                $ids[] = $hit->id;
            }

            $engineTotal = property_exists($result, 'unfilteredTotal') ? $result->unfilteredTotal : $result->total;

            if ($offset + SearchQuery::MAX_LIMIT >= $engineTotal) {
                break;
            }
        }

        return $ids;
    }

    private function engineAllowed(): bool
    {
        $request = $this->requestStack?->getMainRequest();

        if ($this->searchLimiter === null || $request === null) {
            return true;
        }

        $decided = $request->attributes->get(self::ENGINE_ALLOWED_ATTRIBUTE);

        if (\is_bool($decided)) {
            return $decided;
        }

        $allowed = $this->searchLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted();
        $request->attributes->set(self::ENGINE_ALLOWED_ATTRIBUTE, $allowed);

        if (!$allowed) {
            $this->logger?->notice('Product search rate limit reached: the database answers instead of the search engine.');
        }

        return $allowed;
    }

    /**
     * The engine's matches are first narrowed to the visible ones, so that both paths paginate
     * the same list and agree on the total.
     *
     * With a sort, the API sorts and cuts the page out of those ids. Without one, the page is cut
     * here, out of the visible ids in relevance order, and only that page is read through the
     * API: the API cannot order by a relevance it does not know, and cutting its pages by id
     * would scatter the best match anywhere in the results. Page n is thus always the n-th slice
     * of one ordered list, whatever the page size.
     *
     * @param list<int> $matches
     *
     * @return array{products: list<ProductDTO>, total: int}
     */
    private function searchWithEngine(array $matches, int $page, int $itemsPerPage, ?string $sort): array
    {
        $ids = array_values(array_unique($matches));

        if ($this->visibleProductIds !== null) {
            $ids = $this->visibleProductIds->filter($ids);
        }

        if ($ids === []) {
            return ['products' => [], 'total' => 0];
        }

        if ($this->productSort->knows($sort)) {
            return $this->fetch($this->parameters(['id' => $ids], $page, $itemsPerPage, $sort));
        }

        $pageIds = \array_slice($ids, ($page - 1) * $itemsPerPage, $itemsPerPage);

        if ($pageIds === []) {
            return ['products' => [], 'total' => \count($ids)];
        }

        $result = $this->fetch([
            'id' => $pageIds,
            'visible' => true,
            'itemsPerPage' => \count($pageIds),
            'page' => 1,
        ]);

        $rank = array_flip($pageIds);
        $products = $result['products'];
        usort($products, static fn (ProductDTO $left, ProductDTO $right): int => ($rank[$left->id] ?? \PHP_INT_MAX) <=> ($rank[$right->id] ?? \PHP_INT_MAX));

        return ['products' => $products, 'total' => \count($ids)];
    }

    /**
     * @return string|list<string>
     */
    /**
     * @param non-empty-list<string> $terms
     *
     * @return string|list<string>
     */
    private function titleFilter(array $terms): string|array
    {
        if (\count($terms) === 1) {
            return self::literal($terms[0]);
        }

        return array_map(self::literal(...), $terms);
    }

    /**
     * @return list<string>
     */
    private function termsOf(string $term, string $locale): array
    {
        if ($this->coreSearch === null) {
            return [$term];
        }

        if (method_exists($this->coreSearch, 'termsOf')) {
            return $this->coreSearch->termsOf($term, $locale);
        }

        return method_exists($this->coreSearch, 'expand') ? [$term, ...$this->coreSearch->expand($term, $locale)] : [$term];
    }

    private static function literal(string $term): string
    {
        return addcslashes($term, '%_\\');
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array{products: list<ProductDTO>, total: int}
     */
    private function fetch(array $parameters): array
    {
        $response = $this->dataAccessService->resources('/api/front/products', $parameters, 'jsonld');

        return [
            'products' => ProductDTO::fromCollection($response['hydra:member'] ?? []),
            // JSON-LD decoding hands the total back as a float
            'total' => (int) ($response['hydra:totalItems'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return array<string, mixed>
     */
    private function parameters(array $filter, int $page, int $itemsPerPage, ?string $sort): array
    {
        $parameters = $filter + [
            'visible' => true,
            'itemsPerPage' => $itemsPerPage,
            'page' => max(1, $page),
        ];

        return array_merge($parameters, $this->productSort->parameters($sort));
    }
}
