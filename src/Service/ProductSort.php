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

/**
 * The sorts a product listing offers, and the API parameters each of them stands for.
 *
 * Single source for both listing consumers — the ProductListing component and the search
 * service — so a sort added here shows up in the selector and reaches the API at once.
 *
 * Values travel in the query string of a shared or indexed url: they are part of the theme's
 * public surface and must not be renamed.
 */
final readonly class ProductSort
{
    /**
     * Order of the keys is the order of the selector.
     *
     * `parameter` is the API query parameter the sort maps to, `direction` its value.
     */
    private const SORTS = [
        'asc' => ['title' => 'Ascending price', 'parameter' => 'untaxed_price_order', 'direction' => 'asc'],
        'desc' => ['title' => 'Descending price', 'parameter' => 'untaxed_price_order', 'direction' => 'desc'],
        'newest' => ['title' => 'Newest first', 'parameter' => 'order[createdAt]', 'direction' => 'desc'],
        'oldest' => ['title' => 'Oldest first', 'parameter' => 'order[createdAt]', 'direction' => 'asc'],
        'alpha' => ['title' => 'Name A to Z', 'parameter' => 'order[title]', 'direction' => 'asc'],
        'alpha_reverse' => ['title' => 'Name Z to A', 'parameter' => 'order[title]', 'direction' => 'desc'],
    ];

    /**
     * The selector entries, in display order.
     *
     * @return list<array{value: string, title: string}>
     */
    public function choices(): array
    {
        return array_map(
            static fn (string $value, array $sort): array => ['value' => $value, 'title' => $sort['title']],
            array_keys(self::SORTS),
            array_values(self::SORTS),
        );
    }

    /**
     * Whether a value names one of the sorts offered here. A url can carry anything.
     */
    public function knows(?string $sort): bool
    {
        return $sort !== null && \array_key_exists($sort, self::SORTS);
    }

    /**
     * The ordering parameters of an API product query.
     *
     * A sort the theme does not know — a forged or stale url — is no sort at all: the listing
     * falls back on the merchant's own order rather than answering an error.
     *
     * The chosen sort comes first: the API applies the `order[...]` parameters in the order it
     * receives them, so `order[ref]` can only ever be the tiebreaker. That tiebreaker is what
     * keeps pagination stable — products routinely share a position, a price or a title, and
     * paginating without a total order lets one repeat on a page and vanish from another.
     *
     * @return array<string, string>
     */
    public function parameters(?string $sort, ?int $categoryId = null): array
    {
        if (!$this->knows($sort)) {
            $positionProperty = $categoryId !== null ? 'productCategories.position' : 'position';

            return ['order['.$positionProperty.']' => 'asc', 'order[ref]' => 'asc'];
        }

        $chosen = self::SORTS[$sort];

        return [$chosen['parameter'] => $chosen['direction'], 'order[ref]' => 'asc'];
    }
}
