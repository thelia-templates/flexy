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

namespace FlexyBundle\Components\Layouts\AccountOrders;

use FlexyBundle\Service\CollectionPaginator;
use FlexyBundle\Service\CompanyAccount;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Domain\Customer\CustomerFacade;
use Thelia\Model\CustomerQuery;
use Thelia\Model\OrderQuery;

/**
 * Owns the paginated order list: the Twig `resources()` helper cannot ask for the
 * jsonld envelope, and the total count only comes with it.
 */
#[AsTwigComponent]
class Base
{
    public const ITEMS_PER_PAGE = 6;

    /** The orders a buyer of a company placed themselves. */
    public const SCOPE_OWN = 'own';

    /** The orders placed for the company, for a buyer whose role reads them. */
    public const SCOPE_COMPANY = 'company';

    /** The ceiling the core ships for the front API: asking for more would make the pager count wrong. */
    public const MAX_ITEMS_PER_PAGE = CollectionPaginator::MAX_ITEMS_PER_PAGE;

    /** @var list<array<string, mixed>> */
    public array $orders = [];

    /** @var array<string, int> */
    public array $pagination = ['totalItems' => 0, 'itemsPerPage' => self::ITEMS_PER_PAGE, 'currentPage' => 1];

    /** @var array<int, string> who placed each order of a list of the company, by order id */
    public array $placedBy = [];

    public string $scope = self::SCOPE_OWN;

    public function __construct(
        private readonly CollectionPaginator $paginator,
        private readonly CustomerFacade $customerFacade,
        private readonly RequestStack $requestStack,
        private readonly ?CompanyAccount $companyAccount = null,
    ) {
    }

    /**
     * Without a page given, the one of the query string, read raw: CollectionPaginator
     * makes sense of whatever it holds.
     */
    public function mount(mixed $page = null, int $itemsPerPage = self::ITEMS_PER_PAGE): void
    {
        $customer = $this->customerFacade->getCurrentCustomer();

        if (null === $customer) {
            return;
        }

        // The customer filter is the barrier of a customer with no company: read in-process, the
        // collection never meets the core's customer extension (no security token, and the current
        // request is not an /api/front/account route).
        $parameters = [
            'customer.id' => $customer->getId(),
            'order[createdAt]' => 'desc',
        ];

        // A buyer of a company has two lists, their own orders and the ones of the company, and the
        // barrier is the company-aware extension of the API, which bounds the collection to what
        // their role reads: a filter on the owner would drop the orders of their colleagues.
        if (true === $this->companyAccount?->isMember()) {
            $this->scope = $this->requestedScope();
            $parameters = ['order[createdAt]' => 'desc', 'scope' => $this->scope];
        }

        $orders = $this->paginator->page('/api/front/account/orders', $parameters, $page ?? CollectionPaginator::requestedPage($this->requestStack->getCurrentRequest()), $itemsPerPage);

        $this->orders = $orders->members;
        $this->pagination = $orders->pagination();

        if (self::SCOPE_COMPANY === $this->scope) {
            $this->placedBy = $this->buyersOf($this->orders);
        }
    }

    /**
     * The company list only for a buyer whose role reads it, whatever the query string says: the
     * API answers an empty list to anyone else, and the page refuses before it gets here.
     */
    private function requestedScope(): string
    {
        $scope = $this->requestStack->getCurrentRequest()?->query->all()['scope'] ?? null;

        return self::SCOPE_COMPANY === $scope && true === $this->companyAccount?->view()->canViewCompanyOrders ? self::SCOPE_COMPANY : self::SCOPE_OWN;
    }

    /**
     * The name of the buyer who placed each order of the page, read in two statements for the whole page.
     *
     * @param list<array<string, mixed>> $orders
     *
     * @return array<int, string>
     */
    private function buyersOf(array $orders): array
    {
        $orderIds = array_map(static fn (array $order): int => (int) $order['id'], $orders);

        if ([] === $orderIds) {
            return [];
        }

        /** @var array<int, int> $buyerByOrder */
        $buyerByOrder = [];

        foreach (OrderQuery::create()->filterById($orderIds)->select(['Id', 'PlacedByCustomerId', 'CustomerId'])->find() as $row) {
            $buyerByOrder[(int) $row['Id']] = (int) ($row['PlacedByCustomerId'] ?? $row['CustomerId']);
        }

        $names = [];

        foreach (CustomerQuery::create()->filterById(array_values(array_unique($buyerByOrder)))->select(['Id', 'Firstname', 'Lastname'])->find() as $row) {
            $names[(int) $row['Id']] = trim($row['Firstname'].' '.$row['Lastname']);
        }

        $placedBy = [];

        foreach ($buyerByOrder as $orderId => $buyerId) {
            $placedBy[$orderId] = $names[$buyerId] ?? '';
        }

        return $placedBy;
    }
}
