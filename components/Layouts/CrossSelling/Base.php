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
use FlexyBundle\Service\ProductTaxationResolver;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Api\Service\DataAccess\DataAccessService;

#[AsTwigComponent]
class Base
{
    private const DEFAULT_ITEMS_PER_PAGE = 4;

    public int|string|null $categoryId = null;
    public int $itemsPerPage = self::DEFAULT_ITEMS_PER_PAGE;

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
        private readonly ProductTaxationResolver $productTaxationResolver,
    ) {
    }

    public function mount(
        int|string|null $categoryId = null,
        int $itemsPerPage = self::DEFAULT_ITEMS_PER_PAGE,
        array $filters = [],
        array $productIds = [],
    ): void {
        $this->categoryId = $categoryId;
        $this->itemsPerPage = $itemsPerPage;
        $this->filters = $filters;
        $this->productIds = array_values(array_map(intval(...), $productIds));

        $params = [
            'page' => 1,
            'itemsPerPage' => $this->itemsPerPage,
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

        $this->products = $this->inPickedOrder(ProductDTO::fromCollection(
            $this->dataAccessService->resources('/api/front/products', $params) ?? [],
        ));

        // One call for the whole strip instead of one per card.
        $productIds = array_map(static fn (ProductDTO $product): int => $product->id, $this->products);
        $this->productImageResolver->preload($productIds);
        $this->productTaxationResolver->preload($productIds);
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
