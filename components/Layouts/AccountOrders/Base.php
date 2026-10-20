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

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Domain\Customer\CustomerFacade;

/**
 * Owns the paginated order list: the Twig `resources()` helper cannot ask for the
 * jsonld envelope, and the total count only comes with it.
 */
#[AsTwigComponent]
class Base
{
    public const ITEMS_PER_PAGE = 6;

    /** The ceiling the core ships for the front API: asking for more would make the pager count wrong. */
    public const MAX_ITEMS_PER_PAGE = 100;

    /** @var array<int, array<string, mixed>> */
    public array $orders = [];

    /** @var array<string, int> */
    public array $pagination = ['totalItems' => 0, 'itemsPerPage' => self::ITEMS_PER_PAGE, 'currentPage' => 1];

    public function __construct(
        private readonly DataAccessService $dataAccessService,
        private readonly CustomerFacade $customerFacade,
    ) {
    }

    public function mount(int $page = 1, int $itemsPerPage = self::ITEMS_PER_PAGE): void
    {
        $customer = $this->customerFacade->getCurrentCustomer();

        if (null === $customer) {
            return;
        }

        $page = max(1, $page);
        $itemsPerPage = min(max(1, $itemsPerPage), self::MAX_ITEMS_PER_PAGE);
        $response = $this->fetchPage($customer->getId(), $page, $itemsPerPage);
        $totalItems = (int) ($response['hydra:totalItems'] ?? 0);
        $lastPage = max(1, (int) ceil($totalItems / $itemsPerPage));

        // Out of range the API serves the last page anyway; realigning the page number
        // keeps the pager honest instead of offering a "next" that leads nowhere.
        if ($page > $lastPage) {
            $page = $lastPage;
            $response = $this->fetchPage($customer->getId(), $page, $itemsPerPage);
        }

        $this->orders = $response['hydra:member'] ?? [];
        $this->pagination = [
            'totalItems' => $totalItems,
            'itemsPerPage' => $itemsPerPage,
            'currentPage' => $page,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPage(int $customerId, int $page, int $itemsPerPage): array
    {
        $response = $this->dataAccessService->resources('/api/front/account/orders', [
            'customer.id' => $customerId,
            'order[createdAt]' => 'desc',
            'itemsPerPage' => $itemsPerPage,
            'page' => $page,
        ], 'jsonld');

        return \is_array($response) ? $response : [];
    }
}
