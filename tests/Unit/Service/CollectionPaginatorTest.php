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

use FlexyBundle\Service\CollectionPaginator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Api\Service\DataAccess\DataAccessService;

/**
 * The page number of a listing comes straight from the query string. Whatever it holds,
 * the API is asked for a page it can serve, and the pager is drawn on the page served.
 */
final class CollectionPaginatorTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $requests = [];

    /**
     * @param int|string|list<string>|null $asked
     */
    #[DataProvider('pagesThatAreNotAPage')]
    public function testAPageThatIsNotAPositiveIntegerReadsAsTheFirstPage(int|string|array|null $asked): void
    {
        $page = $this->paginator(totalItems: 50)->page('/api/front/things', [], $asked, 10);

        self::assertSame([1], array_column($this->requests, 'page'));
        self::assertSame(1, $page->currentPage);
    }

    /**
     * @return iterable<string, array{int|string|list<string>|null}>
     */
    public static function pagesThatAreNotAPage(): iterable
    {
        yield 'text' => ['abc'];
        yield 'empty' => [''];
        yield 'zero' => [0];
        yield 'negative' => ['-3'];
        yield 'decimal' => ['1.5'];
        yield 'a list, from page[]=1' => [['1']];
        yield 'past what an integer holds' => ['99999999999999999999'];
        yield 'nothing' => [null];
    }

    public function testAPageNumberIsCappedWhereTheOffsetStopsFittingAnInteger(): void
    {
        $this->paginator(totalItems: 50)->page('/api/front/things', [], (string) \PHP_INT_MAX, 30);

        self::assertSame(intdiv(\PHP_INT_MAX, 30), $this->requests[0]['page'], 'The first call already carries a page the API can offset.');
    }

    public function testAPagePastTheLastOneIsServedAsTheLastOne(): void
    {
        $page = $this->paginator(totalItems: 25)->page('/api/front/things', [], '9', 10);

        self::assertSame([9, 3], array_column($this->requests, 'page'));
        self::assertSame(3, $page->currentPage);
        self::assertSame(['totalItems' => 25, 'itemsPerPage' => 10, 'currentPage' => 3], $page->pagination());
    }

    #[DataProvider('sizesAtAndPastTheBounds')]
    public function testThePageSizeStaysWithinWhatTheApiServes(int $asked, int $expected): void
    {
        $page = $this->paginator(totalItems: 500)->page('/api/front/things', [], 1, $asked);

        self::assertSame([$expected], array_column($this->requests, 'itemsPerPage'));
        self::assertSame($expected, $page->itemsPerPage);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function sizesAtAndPastTheBounds(): iterable
    {
        yield 'floor' => [1, 1];
        yield 'ceiling' => [CollectionPaginator::MAX_ITEMS_PER_PAGE, CollectionPaginator::MAX_ITEMS_PER_PAGE];
        yield 'zero' => [0, 1];
        yield 'negative' => [-20, 1];
        yield 'above the API ceiling' => [5000, CollectionPaginator::MAX_ITEMS_PER_PAGE];
    }

    public function testTheFiltersAreSentAsTheyAreAndTheMembersAreReindexed(): void
    {
        $page = $this->paginator(totalItems: 2, members: [7 => ['id' => 1], 9 => ['id' => 2]])
            ->page('/api/front/things', ['customer.id' => 42, 'order[createdAt]' => 'desc'], 1, 10);

        self::assertSame(42, $this->requests[0]['customer.id']);
        self::assertSame('desc', $this->requests[0]['order[createdAt]']);
        self::assertSame([['id' => 1], ['id' => 2]], $page->members);
    }

    public function testTheRequestedPageIsReadRawFromTheQueryString(): void
    {
        self::assertSame('3', CollectionPaginator::requestedPage(Request::create('/x?page=3')));
        self::assertSame(['1'], CollectionPaginator::requestedPage(Request::create('/x?page[]=1')), 'An array is read by boundedPage() as the first page, where InputBag::get() would answer a 400.');
        self::assertSame(1, CollectionPaginator::requestedPage(Request::create('/x')));
        self::assertSame(1, CollectionPaginator::requestedPage(null));
    }

    /**
     * @param array<int, array<string, mixed>> $members
     */
    private function paginator(int $totalItems, array $members = []): CollectionPaginator
    {
        $dataAccess = $this->createStub(DataAccessService::class);
        $dataAccess->method('resources')->willReturnCallback(
            function (string $path, array $parameters) use ($totalItems, $members): array {
                $this->requests[] = $parameters;

                return ['hydra:totalItems' => $totalItems, 'hydra:member' => $members];
            },
        );

        return new CollectionPaginator($dataAccess);
    }
}
