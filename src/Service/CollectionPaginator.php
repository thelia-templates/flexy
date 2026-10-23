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

use FlexyBundle\DTO\CollectionPage;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Api\Service\DataAccess\DataAccessService;

/**
 * Reads one page of an API collection from a page number that comes straight from the
 * query string, so anything may arrive: garbage is served as the first page, and a page
 * past the last one as the last page.
 *
 * The requested page is capped before the first call: API Platform refuses a page whose
 * offset no longer fits an integer (a 400 over HTTP, an exception in process), and that
 * refusal would surface as a 500. The page size is kept within what the API serves too: a
 * pager counting with a size the API did not honour counts wrong.
 */
final readonly class CollectionPaginator
{
    /** The ceiling the core ships for the front API: asking for more would make the pager count wrong. */
    public const int MAX_ITEMS_PER_PAGE = 100;

    public function __construct(
        private DataAccessService $dataAccessService,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters filters and ordering, without paging
     */
    public function page(string $path, array $parameters, mixed $requestedPage, int $itemsPerPage): CollectionPage
    {
        $itemsPerPage = self::boundedItemsPerPage($itemsPerPage);
        $page = self::boundedPage($requestedPage, $itemsPerPage);
        $response = $this->fetch($path, $parameters, $page, $itemsPerPage);
        $totalItems = (int) ($response['hydra:totalItems'] ?? 0);
        $lastPage = max(1, (int) ceil($totalItems / $itemsPerPage));

        if ($page > $lastPage) {
            $page = $lastPage;
            $response = $this->fetch($path, $parameters, $page, $itemsPerPage);
        }

        $members = $response['hydra:member'] ?? [];

        return new CollectionPage(
            \is_array($members) ? array_values($members) : [],
            $totalItems,
            $itemsPerPage,
            $page,
        );
    }

    /**
     * The page asked for in the query string, raw: `page[]=1` comes back as an array, which
     * boundedPage() reads as the first page, where InputBag::get() would answer a 400.
     */
    public static function requestedPage(?Request $request): mixed
    {
        return $request?->query->all()['page'] ?? 1;
    }

    /**
     * A page size the API serves as asked: between one and MAX_ITEMS_PER_PAGE.
     */
    public static function boundedItemsPerPage(int $itemsPerPage): int
    {
        return min(max(1, $itemsPerPage), self::MAX_ITEMS_PER_PAGE);
    }

    /**
     * A page number safe to hand to the API, whatever the query string held: anything that
     * is not a positive integer reads as the first page, and nothing goes past the page
     * whose offset still fits an integer. Public for the listings that page through
     * another service than this one.
     */
    public static function boundedPage(mixed $requestedPage, int $itemsPerPage): int
    {
        $page = filter_var($requestedPage, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return \is_int($page) ? min($page, intdiv(\PHP_INT_MAX, self::boundedItemsPerPage($itemsPerPage))) : 1;
    }

    /**
     * The jsonld envelope, the only one carrying the total count.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private function fetch(string $path, array $parameters, int $page, int $itemsPerPage): array
    {
        $response = $this->dataAccessService->resources($path, [
            ...$parameters,
            'itemsPerPage' => $itemsPerPage,
            'page' => $page,
        ], 'jsonld');

        return \is_array($response) ? $response : [];
    }
}
