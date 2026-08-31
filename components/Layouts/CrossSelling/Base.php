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
    ): void {
        $this->categoryId = $categoryId;
        $this->itemsPerPage = $itemsPerPage;
        $this->filters = $filters;

        $params = [
            'page' => 1,
            'itemsPerPage' => $this->itemsPerPage,
            'visible' => true,
        ];

        if ($this->categoryId !== null) {
            $params['productCategories.category.id'] = $this->categoryId;
        }

        $params = array_merge($params, $this->filters);

        $this->products = ProductDTO::fromCollection(
            $this->dataAccessService->resources('/api/front/products', $params) ?? [],
        );

        // One call for the whole strip instead of one per card.
        $productIds = array_map(static fn (ProductDTO $product): int => $product->id, $this->products);
        $this->productImageResolver->preload($productIds);
        $this->productTaxationResolver->preload($productIds);
    }
}
