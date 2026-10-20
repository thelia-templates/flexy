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

namespace FlexyBundle\Components\Organisms\ProductCard;

use FlexyBundle\DTO\OrderProductDTO;
use FlexyBundle\DTO\ProductDTO;
use FlexyBundle\Service\OrderProductResolver;
use FlexyBundle\Service\ProductImageResolver;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Api\Service\DataAccess\DataAccessService;

#[AsTwigComponent]
class Order extends AbstractProductCard
{
    public OrderProductDTO $orderProduct;
    private ?array $pse = null;

    /** The currency the order was placed in; the pages that do not say keep euros. */
    public string $currencyCode = 'EUR';

    public function __construct(
        DataAccessService $dataAccessService,
        ProductImageResolver $productImageResolver,
        private readonly OrderProductResolver $orderProductResolver,
    ) {
        parent::__construct($dataAccessService, $productImageResolver);
    }

    /**
     * Every lookup is skippable: a caller holding several lines resolves them in one
     * batch (see Layouts/OrderProducts) and hands the results over. Alone, the card
     * still stands on its own.
     */
    public function mount(
        array $orderProduct,
        ProductDTO|array|null $product = null,
        ?array $pse = null,
        ?int $imageId = null,
    ): void {
        $this->orderProduct = OrderProductDTO::fromArray($orderProduct);
        $saleElementId = $this->orderProduct->productSaleElementsId;

        // OrderProduct exposes no product id, so `loadProduct()` alone would come back
        // empty: the product has to be reached through the sale element.
        $product ??= $this->orderProductResolver->resolveProduct($saleElementId);
        $this->loadProduct($product, $this->orderProduct->productId ?: null);

        // The sale element may carry its own visual, so this beats the parent's
        // product-level lookup.
        $this->imageId = $imageId
            ?? $this->orderProductResolver->resolveImageId($saleElementId, $this->product?->id);

        $this->pse = $pse ?? $this->dataAccessService->resources(
            '/api/front/product_sale_elements/' . $saleElementId
        );
    }

    public function getProductUrl(): ?string
    {
        return $this->orderProductResolver->buildProductUrl(
            $this->product,
            $this->orderProduct->productSaleElementsRef,
        );
    }

    public function getPse(): ?array
    {
        return $this->pse;
    }
}
