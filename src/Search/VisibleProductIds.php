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

namespace FlexyBundle\Search;

use Thelia\Domain\Sale\ReservedSaleVisibility;
use Thelia\Model\Map\ProductTableMap;
use Thelia\Model\ProductQuery;

/**
 * The products of a search engine's answer a visitor may see, in the engine's order.
 *
 * Paginating by relevance needs the whole visible list before the first page is cut, and the
 * product API only answers with full product payloads: one id query here costs less than
 * normalising every match. It applies the two rules the front product API applies on its own
 * — the `visible` flag and the operations reserved to some customers — and the page itself is
 * still read through the API, which filters again: a rule added there later can shorten a page,
 * never show a product it hides.
 *
 * Not final so a unit test can stand in for the database.
 */
readonly class VisibleProductIds
{
    public function __construct(
        private ReservedSaleVisibility $reservedSaleVisibility,
    ) {
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public function filter(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $query = ProductQuery::create()->filterById($ids)->filterByVisible(1);
        $this->reservedSaleVisibility->applyTo($query, ProductTableMap::COL_ID);

        $visible = array_flip(array_map('intval', $query->select('Id')->find()->toArray()));

        return array_values(array_filter($ids, static fn (int $id): bool => isset($visible[$id])));
    }
}
