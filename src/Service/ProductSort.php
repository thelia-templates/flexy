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

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Thelia\Domain\Catalog\Product\ProductSortProviderInterface;

/**
 * The sorts a product listing offers, and the API parameters each of them stands for.
 *
 * Single source for both listing consumers — the ProductListing component and the search
 * service — so a sort added here shows up in the selector and reaches the API at once.
 *
 * The list is open: an order reading data a module publishes is declared by that module, as a
 * Thelia\Domain\Catalog\Product\ProductSortProviderInterface service, rather than written
 * below. The theme therefore names no module, and an order is offered exactly on the shops where
 * the data behind it exists. The sorts declared here read native product columns and hold
 * everywhere.
 *
 * Values travel in the query string of a shared or indexed url: they are part of the theme's
 * public surface and must not be renamed.
 */
final readonly class ProductSort
{
    /**
     * `position` is the rank in the selector, on a scale left deliberately sparse so a
     * contributed sort can slot between two of these without renumbering them.
     *
     * `parameters` are the API query parameters the sort maps to.
     */
    private const SORTS = [
        'asc' => ['title' => 'Ascending price', 'position' => 10, 'parameters' => ['untaxed_price_order' => 'asc']],
        'desc' => ['title' => 'Descending price', 'position' => 20, 'parameters' => ['untaxed_price_order' => 'desc']],
        'newest' => ['title' => 'Newest first', 'position' => 40, 'parameters' => ['order[createdAt]' => 'desc']],
        'oldest' => ['title' => 'Oldest first', 'position' => 50, 'parameters' => ['order[createdAt]' => 'asc']],
        'alpha' => ['title' => 'Name A to Z', 'position' => 60, 'parameters' => ['order[title]' => 'asc']],
        'alpha_reverse' => ['title' => 'Name Z to A', 'position' => 70, 'parameters' => ['order[title]' => 'desc']],
    ];

    /**
     * @param iterable<ProductSortProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('thelia.catalog.product_sort')]
        private iterable $providers = [],
    ) {
    }

    /**
     * The selector entries, in display order.
     *
     * @return list<array{value: string, title: string}>
     */
    public function choices(): array
    {
        return array_map(
            static fn (string $value, array $sort): array => ['value' => $value, 'title' => $sort['title']],
            array_keys($this->sorts()),
            array_values($this->sorts()),
        );
    }

    /**
     * Whether a value names one of the sorts offered here. A url can carry anything.
     */
    public function knows(?string $sort): bool
    {
        return $sort !== null && \array_key_exists($sort, $this->sorts());
    }

    /**
     * The ordering parameters of an API product query.
     *
     * A sort the theme does not know — a forged or stale url, or one declared by a module this
     * shop no longer has — is no sort at all: the listing falls back on the merchant's own order
     * rather than answering an error.
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
        $sorts = $this->sorts();

        if ($sort === null || !\array_key_exists($sort, $sorts)) {
            $positionProperty = $categoryId !== null ? 'productCategories.position' : 'position';

            return ['order['.$positionProperty.']' => 'asc', 'order[ref]' => 'asc'];
        }

        return $sorts[$sort]['parameters'] + ['order[ref]' => 'asc'];
    }

    /**
     * The sorts this shop offers, in display order.
     *
     * A provider reusing a value declared above replaces it, which is how a project swaps one of
     * the sorts below for its own without editing this file. Providers sharing a position keep
     * the order they were declared in: uasort is stable.
     *
     * @return array<string, array{title: string, position: int, parameters: array<string, string>}>
     */
    private function sorts(): array
    {
        $sorts = self::SORTS;

        foreach ($this->providers as $provider) {
            $sorts[$provider->value()] = [
                'title' => $provider->title(),
                'position' => $provider->position(),
                'parameters' => $provider->parameters(),
            ];
        }

        uasort($sorts, static fn (array $left, array $right): int => $left['position'] <=> $right['position']);

        return $sorts;
    }
}
