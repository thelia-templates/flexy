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

namespace FlexyBundle\Tests\Unit;

use FlexyBundle\Components\Layouts\AccountOrders\Base as AccountOrders;
use FlexyBundle\Service\CollectionPaginator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Domain\Customer\CustomerFacade;
use Thelia\Model\Customer;

/**
 * A child theme sets the page size of the account order list from its template. The size
 * asked of the API, the one the pager counts with and the last page must all agree.
 */
final class AccountOrdersPageSizeTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $requests = [];

    public function testTheDefaultPageSizeIsUnchanged(): void
    {
        $orders = $this->mountedList(totalItems: 110);

        self::assertSame([6], array_column($this->requests, 'itemsPerPage'));
        self::assertSame(['totalItems' => 110, 'itemsPerPage' => 6, 'currentPage' => 1], $orders->pagination);
    }

    public function testAChildThemeSetsThePageSize(): void
    {
        $orders = $this->mountedList(totalItems: 110, itemsPerPage: 20);

        self::assertSame([20], array_column($this->requests, 'itemsPerPage'));
        self::assertSame(['totalItems' => 110, 'itemsPerPage' => 20, 'currentPage' => 1], $orders->pagination);
    }

    public function testTheLastPageFollowsThePageSize(): void
    {
        $orders = $this->mountedList(totalItems: 110, itemsPerPage: 20, page: 9);

        self::assertSame([9, 6], array_column($this->requests, 'page'));
        self::assertSame(6, $orders->pagination['currentPage']);
    }

    /**
     * Read in-process, the collection meets no customer extension of the core: this filter is
     * the only thing keeping the list to the signed-in customer.
     */
    public function testTheListIsLimitedToTheSignedInCustomer(): void
    {
        $this->mountedList(totalItems: 3);

        self::assertSame([42], array_column($this->requests, 'customer.id'));
    }

    #[DataProvider('sizesAtAndPastTheBounds')]
    public function testTheSizeIsKeptWithinWhatTheApiServes(int $asked, int $expected): void
    {
        $orders = $this->mountedList(totalItems: 110, itemsPerPage: $asked);

        self::assertSame([$expected], array_column($this->requests, 'itemsPerPage'));
        self::assertSame($expected, $orders->pagination['itemsPerPage']);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function sizesAtAndPastTheBounds(): iterable
    {
        yield 'floor' => [1, 1];
        yield 'ceiling' => [100, 100];
        yield 'zero' => [0, 1];
        yield 'negative' => [-20, 1];
        yield 'above the API ceiling' => [5000, 100];
    }

    private function mountedList(int $totalItems, ?int $itemsPerPage = null, int $page = 1): AccountOrders
    {
        $customer = new Customer();
        $customer->setId(42);

        $customerFacade = $this->createStub(CustomerFacade::class);
        $customerFacade->method('getCurrentCustomer')->willReturn($customer);

        $dataAccess = $this->createStub(DataAccessService::class);
        $dataAccess->method('resources')->willReturnCallback(
            function (string $path, array $parameters) use ($totalItems): array {
                $this->requests[] = $parameters;

                return ['hydra:totalItems' => $totalItems, 'hydra:member' => []];
            },
        );

        $orders = new AccountOrders(new CollectionPaginator($dataAccess), $customerFacade, new RequestStack());

        if (null === $itemsPerPage) {
            $orders->mount($page);
        } else {
            $orders->mount($page, $itemsPerPage);
        }

        return $orders;
    }
}
