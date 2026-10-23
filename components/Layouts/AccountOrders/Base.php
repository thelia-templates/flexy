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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
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
    public const MAX_ITEMS_PER_PAGE = CollectionPaginator::MAX_ITEMS_PER_PAGE;

    /** @var list<array<string, mixed>> */
    public array $orders = [];

    /** @var array<string, int> */
    public array $pagination = ['totalItems' => 0, 'itemsPerPage' => self::ITEMS_PER_PAGE, 'currentPage' => 1];

    public function __construct(
        private readonly CollectionPaginator $paginator,
        private readonly CustomerFacade $customerFacade,
        private readonly RequestStack $requestStack,
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

        // The customer filter is the barrier: read in-process, the collection never meets
        // the core's customer extension (no security token, and the current request is not
        // an /api/front/account route).
        $orders = $this->paginator->page('/api/front/account/orders', [
            'customer.id' => $customer->getId(),
            'order[createdAt]' => 'desc',
        ], $page ?? CollectionPaginator::requestedPage($this->requestStack->getCurrentRequest()), $itemsPerPage);

        $this->orders = $orders->members;
        $this->pagination = $orders->pagination();
    }
}
