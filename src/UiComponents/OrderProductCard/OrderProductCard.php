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

namespace FlexyBundle\UiComponents\OrderProductCard;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use TheliaLibrary\Service\ImagePluginService;
use TwigEngine\Service\DataAccess\DataAccessService;

#[AsTwigComponent(name: 'Flexy:OrderProductCard', template: '@UiComponents/OrderProductCard/OrderProductCard.html.twig')]
class OrderProductCard
{
    public array $orderProduct;

    public function __construct(
        private readonly DataAccessService $dataAccessService,
        private readonly ImagePluginService $imagePluginService,
    ) {
    }

    public function getProduct(): array
    {
        return $this->dataAccessService->resources('/api/front/products/'.$this->orderProduct['productId']);
    }

    public function getPse(): array
    {
        return $this->dataAccessService->resources('/api/front/product_sale_elements/'.$this->orderProduct['productSaleElementsId']);
    }

    public function getImageId(): int
    {
        $pseImg = $this->dataAccessService->resources('/api/front/product_sale_elements_product_image', [
            'productSaleElementsId' => $this->orderProduct['productSaleElementsId'],
        ]);

        if (empty($pseImg)) {
            $pseImg = $this->dataAccessService->resources('/api/front/product_images', [
                'product.id' => $this->orderProduct['productId'],
            ]);
            return $pseImg[0]['id'];

        }

        return $pseImg[0]['productImageId'];
    }

    public function getImage(): string
    {
        return $this->imagePluginService->getImages([
            'img_id' => $this->getImageId(),
            'source_type' => 'product',
            'filters' => 'order_card_detail',
            'wrapper' => 'figure',
            'wrapper_attrs' => [
                'class' => 'OrderProductCard-img',
            ],
            'limit' => 1,
            'img_attrs' => [
                'loading' => 'lazy',
                'widht' => '112',
                'height' => '112',
            ],
        ]);
    }
}
