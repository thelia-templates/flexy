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
use FlexyBundle\Search\VisibleProductIds;
use FlexyBundle\Service\ProductSearch;
use FlexyBundle\Service\ProductSort;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Domain\Localization\Service\LangService;

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

    protected function setUp(): void
    {
        $this->apiCalls = [];
        $this->engineCalls = [];
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

    /**
     * @param list<int>      $catalog   ids the API knows, which it returns in ascending id order
     * @param list<int>|null $engineIds null for no engine
     * @param list<int>|null $visible   null when every id is visible
     */
    private function productSearch(array $catalog, ?array $engineIds = null, ?array $visible = null): ProductSearch
    {
        $visibleProductIds = $this->createStub(VisibleProductIds::class);
        $visibleProductIds->method('filter')->willReturnCallback(
            static fn (array $ids): array => $visible === null ? $ids : array_values(array_filter($ids, static fn (int $id): bool => \in_array($id, $visible, true))),
        );

        return new ProductSearch(
            $this->dataAccess($catalog),
            new ProductSort(),
            new EventDispatcher(),
            $this->langService(),
            $engineIds === null ? [] : [$this->engine($engineIds)],
            $visibleProductIds,
        );
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
