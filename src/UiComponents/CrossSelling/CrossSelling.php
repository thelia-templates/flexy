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

namespace FlexyBundle\UiComponents\CrossSelling;

use FlexyBundle\DTO\ProductDTO;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use Thelia\Api\Service\DataAccess\DataAccessService;

#[AsTwigComponent(name: 'Flexy:CrossSelling', template: '@UiComponents/CrossSelling/CrossSelling.html.twig')]
class CrossSelling
{
    public ?string $categoryId = null;
    public array $productIdsToIgnore = [];
    public int $itemsPerPage = 4;
    public bool $promo = false;
    public bool $new = false;

    public array $products = [];

    public function __construct(private DataAccessService $dataAccessService)
    {
    }

    public function mount(bool $promo = false, bool $new = false, array $products = []): void
    {
        $this->promo = $promo;
        $this->new = $new;

        if (0 === \count($products)) {
            $this->products = $this->fetchProducts();
            return;
        }

        $this->products = ProductDTO::fromCollection($products);
    }

    /**
     * @return ProductDTO[]
     */
    private function fetchProducts(): array
    {
        $params = [
            'page' => 1,
            'itemsPerPage' => $this->itemsPerPage,
            'visible' => true,
        ];

        if (\count($this->productIdsToIgnore) > 0) {
            $params['not_in[id]'] = $this->productIdsToIgnore;
        }

        if ($this->promo) {
            $params['productSaleElements.promo'] = true;
        }

        if ($this->new) {
            $params['productSaleElements.newness'] = true;
        }

        if (null !== $this->categoryId) {
            $params['productCategories.category.id'] = $this->categoryId;
        }

        $products = ProductDTO::fromCollection(
            $this->dataAccessService->resources('/api/front/products', $params)
        );

        return $products;
    }
}
