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

namespace FlexyBundle\Components\Layouts\CrossSelling;

use FlexyBundle\DTO\ProductDTO;
use FlexyBundle\Service\ProductImageResolver;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Api\Service\DataAccess\DataAccessService;

/**
 * A strip of products: a category, a hand-picked list of ids, or the relations a
 * merchant filed under one type for one product.
 *
 * The typed strip reads `/api/front/product_associations`, which carries the
 * related product whole rather than as a link, so a block costs one call, cards
 * included, already in the order the merchant arranged. Visibility is not passed
 * to it: that endpoint hands out neither a product taken offline nor the
 * relations of a hidden type, so a caller cannot widen it.
 */
#[AsTwigComponent]
class Base
{
    private const DEFAULT_ITEMS_PER_PAGE = 4;

    /**
     * A block holds what a merchant picked by hand, so it is short, and cutting it
     * at the four of a category strip would drop relations without saying so. The
     * slider carries the rest.
     */
    private const DEFAULT_RELATIONS_PER_BLOCK = 12;

    public int|string|null $categoryId = null;
    public int $itemsPerPage = self::DEFAULT_ITEMS_PER_PAGE;

    /** Read the relations of this product, under {@see $typeCode}, instead of a category. */
    public int|string|null $productId = null;
    public ?string $typeCode = null;

    /** Kept out of a category strip: the sheet a strip is shown on does not suggest itself. */
    public ?int $excludeProductId = null;

    /** @var array<string, mixed> extra /api/front/products query parameters, e.g. {'productSaleElements.promo': true} */
    public array $filters = [];

    /**
     * A hand-picked list of product ids, rendered in the order it is given instead of the one
     * the API answers in. Empty means the strip is built from `categoryId` and `filters`.
     *
     * @var int[]
     */
    public array $productIds = [];

    /** @var ProductDTO[] */
    public array $products = [];

    public function __construct(
        private readonly DataAccessService $dataAccessService,
        private readonly ProductImageResolver $productImageResolver,
    ) {
    }

    public function mount(
        int|string|null $categoryId = null,
        ?int $itemsPerPage = null,
        array $filters = [],
        array $productIds = [],
        int|string|null $productId = null,
        ?string $typeCode = null,
        int|string|null $excludeProductId = null,
    ): void {
        $this->categoryId = $categoryId;
        $this->filters = $filters;
        $this->productIds = array_values(array_map(intval(...), $productIds));
        $this->productId = $productId;
        $this->typeCode = $typeCode;
        $this->excludeProductId = $excludeProductId === null ? null : (int) $excludeProductId;

        $readsRelations = $this->productId !== null && $this->typeCode !== null;

        $this->itemsPerPage = $itemsPerPage
            ?? ($readsRelations ? self::DEFAULT_RELATIONS_PER_BLOCK : self::DEFAULT_ITEMS_PER_PAGE);

        if ($readsRelations) {
            $this->products = $this->relatedProducts();
            $this->preloadCardsOf($this->products);

            return;
        }

        // One more is asked for so dropping the excluded product does not shorten the strip.
        $params = [
            'page' => 1,
            'itemsPerPage' => $this->itemsPerPage + ($this->excludeProductId === null ? 0 : 1),
            'visible' => true,
        ];

        if ($this->categoryId !== null) {
            $params['productCategories.category.id'] = $this->categoryId;
        }

        if ($this->productIds !== []) {
            // A picked list is asked for whole: paging it would drop the tail silently.
            $params['id'] = $this->productIds;
            $params['itemsPerPage'] = \count($this->productIds);
        }

        // `filters` has the last word, as it always had: a caller that sets `id` or
        // `itemsPerPage` there overrides what `productIds` asked for.
        $params = array_merge($params, $this->filters);

        $this->products = $this->withoutTheExcludedProduct($this->inPickedOrder(ProductDTO::fromCollection(
            $this->dataAccessService->resources('/api/front/products', $params) ?? [],
        )));

        $this->preloadCardsOf($this->products);
    }

    /**
     * @return ProductDTO[]
     */
    private function relatedProducts(): array
    {
        $relations = $this->dataAccessService->resources('/api/front/product_associations', [
            'page' => 1,
            'itemsPerPage' => $this->itemsPerPage,
            'product.id' => $this->productId,
            'type.code' => $this->typeCode,
            'order[position]' => 'asc',
        ]) ?? [];

        $relatedProducts = [];

        foreach ($relations as $relation) {
            $relatedProduct = $relation['associatedProduct'] ?? null;

            if (\is_array($relatedProduct)) {
                $relatedProducts[] = $relatedProduct;
            }
        }

        return ProductDTO::fromCollection($relatedProducts);
    }

    /**
     * A category strip shown on a product sheet would otherwise offer the very product
     * being read. The strip is cut back to the length that was asked for.
     *
     * @param ProductDTO[] $products
     *
     * @return ProductDTO[]
     */
    private function withoutTheExcludedProduct(array $products): array
    {
        if ($this->excludeProductId === null) {
            return $products;
        }

        $kept = array_values(array_filter(
            $products,
            fn (ProductDTO $product): bool => $product->id !== $this->excludeProductId,
        ));

        return \array_slice($kept, 0, $this->itemsPerPage);
    }

    /**
     * One call for the whole strip instead of one per card.
     *
     * @param ProductDTO[] $products
     */
    private function preloadCardsOf(array $products): void
    {
        $productIds = array_map(static fn (ProductDTO $product): int => $product->id, $products);
        $this->productImageResolver->preload($productIds);
    }

    /**
     * The API answers a list of ids in its own order, so a picked strip is put back in the
     * order it was picked in. A product missing from the answer — hidden since it was picked,
     * or gone — is dropped rather than leaving a hole.
     *
     * @param ProductDTO[] $products
     *
     * @return ProductDTO[]
     */
    private function inPickedOrder(array $products): array
    {
        if ($this->productIds === []) {
            return $products;
        }

        $byId = [];
        foreach ($products as $product) {
            $byId[$product->id] = $product;
        }

        $picked = [];
        foreach ($this->productIds as $id) {
            if (isset($byId[$id])) {
                $picked[] = $byId[$id];
            }
        }

        return $picked;
    }
}
