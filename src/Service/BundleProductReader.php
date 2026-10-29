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

use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Domain\Catalog\Bundle\BundleComposition;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * The components of a bundle as its sheet lists them, read once when the sheet is mounted.
 *
 * The components come from the front API of the bundle items, which publishes the sellable ones
 * only: a component taken offline, or whose sale element is gone, is not on the page, and the
 * core refuses the bundle at the cart when a REQUIRED one is missing. Nothing here decides what
 * a bundle may hold: bounds are the merchant's, the core re-checks them on every price and every add.
 *
 * Each row is scalars only, since it travels in a LiveComponent prop (signed, posted back with every
 * action) and so has to stay small.
 */
final readonly class BundleProductReader
{
    /** The most the front API gives in one page. */
    private const PAGE_SIZE = 100;

    public function __construct(
        private DataAccessService $dataAccessService,
    ) {
    }

    /**
     * @return list<array{itemId: int, productId: int, title: string, required: bool, quantity: string, min: string|null, max: string|null}>
     */
    public function read(int $bundleProductId): array
    {
        $items = $this->items($bundleProductId);

        if ([] === $items) {
            return [];
        }

        $items = array_values(array_filter($items, '\is_array'));
        $saleElementIds = array_values(array_filter(array_map(
            static fn (array $item): ?int => self::relationId($item['productSaleElements'] ?? null),
            $items,
        )));

        // One query for the whole list: the product behind each sale element and the reference to fall back on.
        $productIdBySaleElement = [];
        $referenceBySaleElement = [];

        foreach (ProductSaleElementsQuery::create()->filterById($saleElementIds)->find() as $saleElement) {
            $productIdBySaleElement[(int) $saleElement->getId()] = (int) $saleElement->getProductId();
            $referenceBySaleElement[(int) $saleElement->getId()] = (string) $saleElement->getRef();
        }

        $titles = $this->titlesOf(array_values(array_unique($productIdBySaleElement)));
        $rows = [];

        foreach ($items as $item) {
            $saleElementId = self::relationId($item['productSaleElements'] ?? null);

            if (null === $saleElementId || !isset($productIdBySaleElement[$saleElementId])) {
                continue;
            }

            $productId = $productIdBySaleElement[$saleElementId];
            $quantity = BundleLines::formatQuantity($item['quantity'] ?? 1);

            $rows[] = [
                'itemId' => (int) $item['id'],
                'productId' => $productId,
                'title' => '' !== ($titles[$productId] ?? '') ? $titles[$productId] : $referenceBySaleElement[$saleElementId],
                'required' => (bool) ($item['required'] ?? true),
                'quantity' => $quantity,
                'min' => isset($item['minQuantity']) ? BundleLines::formatQuantity($item['minQuantity']) : null,
                'max' => isset($item['maxQuantity']) ? BundleLines::formatQuantity($item['maxQuantity']) : null,
            ];
        }

        return $rows;
    }

    /**
     * Every item of the bundle, page after page: the API never gives more than PAGE_SIZE at once, and a
     * bundle may hold as many components as a composition may have lines (BundleComposition::MAX_LINES).
     *
     * @return list<array<string, mixed>>
     */
    private function items(int $bundleProductId): array
    {
        $items = [];

        for ($page = 1; $page <= (int) ceil(BundleComposition::MAX_LINES / self::PAGE_SIZE); ++$page) {
            $read = $this->dataAccessService->resources('/api/front/product_bundle_items', [
                'bundleProduct.id' => $bundleProductId,
                'order[position]' => 'asc',
                'itemsPerPage' => self::PAGE_SIZE,
                'page' => $page,
            ]);

            if (!\is_array($read) || [] === $read) {
                break;
            }

            foreach ($read as $item) {
                // Keyed by id: a page the API would answer again cannot list a component twice.
                if (\is_array($item)) {
                    $items[$item['id'] ?? \count($items)] = $item;
                }
            }

            if (\count($read) < self::PAGE_SIZE) {
                break;
            }
        }

        return array_values($items);
    }

    /**
     * @param list<int> $productIds
     *
     * @return array<int, string>
     */
    private function titlesOf(array $productIds): array
    {
        if ([] === $productIds) {
            return [];
        }

        $titles = [];

        // The API gives PAGE_SIZE products at most in one answer.
        foreach (array_chunk($productIds, self::PAGE_SIZE) as $chunk) {
            $products = $this->dataAccessService->resources('/api/front/products', [
                'id' => $chunk,
                'itemsPerPage' => \count($chunk),
            ]);

            foreach (\is_array($products) ? $products : [] as $product) {
                if (\is_array($product) && isset($product['id'])) {
                    $titles[(int) $product['id']] = trim((string) ($product['i18ns']['title'] ?? ''));
                }
            }
        }

        return $titles;
    }

    /**
     * A relation comes back either embedded or as an IRI, depending on the group it was read in.
     */
    private static function relationId(mixed $relation): ?int
    {
        if (\is_array($relation)) {
            return isset($relation['id']) ? (int) $relation['id'] : null;
        }

        if (\is_string($relation) && preg_match('#/(\d+)$#', $relation, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
