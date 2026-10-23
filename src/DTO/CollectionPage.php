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

namespace FlexyBundle\DTO;

/**
 * One page of an API collection, with what Molecules:Pagination:Base needs to draw
 * the pager: the page actually served, which may differ from the one asked for.
 */
final readonly class CollectionPage
{
    /**
     * @param list<array<string, mixed>> $members
     */
    public function __construct(
        public array $members,
        public int $totalItems,
        public int $itemsPerPage,
        public int $currentPage,
    ) {
    }

    /**
     * @return array{totalItems: int, itemsPerPage: int, currentPage: int}
     */
    public function pagination(): array
    {
        return [
            'totalItems' => $this->totalItems,
            'itemsPerPage' => $this->itemsPerPage,
            'currentPage' => $this->currentPage,
        ];
    }
}
