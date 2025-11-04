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

namespace FlexyBundle\UiComponents\CartItem;

use FlexyBundle\DTO\CartItemDto;
use FlexyBundle\Service\ProductSaleElementsService;
use Propel\Runtime\Map\TableMap;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Taxation\TaxEngine\TaxEngine;
use Thelia\Model\CartItemQuery;
use Thelia\Model\ProductImage;
use Thelia\Model\ProductSaleElementsProductImage;

#[AsTwigComponent(name: 'Flexy:CartItem', template: '@UiComponents/CartItem/CartItem.html.twig')]
class CartItem
{
    public CartItemDto $cartItem;
    public bool $outOfStock;
    public ?int $pseImageId;

    public array $prices = [];

    public string $title = '';

    public ?string $desc = '';

    public ?array $attributesAv = null;

    public function __construct(
        private readonly ProductSaleElementsService $pseService,
        private readonly TaxEngine $taxEngine,
        private readonly LangService $langService,
    ) {
    }

    public function mount(CartItemDto $cartItem): void
    {
        $this->cartItem = $cartItem;
        $cartItemModel = CartItemQuery::create()->findPk($this->cartItem->id);
        $pse = $cartItemModel->getProductSaleElements();

        $taxCountry = $this->taxEngine->getDeliveryCountry();

        // TODO : Refacto cartItem DTO
        $this->prices['price'] = $cartItemModel->getPrice();
        $this->prices['promoPrice'] = $cartItemModel->getPromoPrice();
        $this->prices['taxedPrice'] = $cartItemModel->getTaxedPrice($taxCountry);
        $this->prices['promoTaxedPrice'] = $cartItemModel->getTaxedPromoPrice($taxCountry);

        $this->prices['totalPrice'] = $cartItemModel->getTotalPrice();
        $this->prices['totalPromoPrice'] = $cartItemModel->getTotalPromoPrice();
        $this->prices['totalTaxedPrice'] = $cartItemModel->getTotalTaxedPrice($taxCountry);
        $this->prices['totalPromoTaxedPrice'] = $cartItemModel->getTotalTaxedPromoPrice($taxCountry);

        $this->title = $cartItemModel->getProduct()->getTitle();
        $this->desc = $cartItemModel->getProduct()->getChapo();
        $this->url = $cartItemModel->getProduct()->getUrl($this->langService->getLocale());

        $this->outOfStock = $this->cartItem->stock <= 0;
        $this->attributesAv = $this->pseService->getAttributesAvFromPse($cartItemModel->getProductSaleElements());

        /** @var ProductSaleElementsProductImage $pseImage */
        $pseImage = $pse->getProductSaleElementsProductImages()->getFirst();
        $this->pse = $pse->toArray(TableMap::TYPE_CAMELNAME);

        if ($pseImage) {
            $this->pseImageId = $pseImage->getProductImageId();

            return;
        }
        /** @var ProductImage $productImage */
        $productImage = $cartItemModel->getProduct()->getProductImages()->getFirst();

        if ($productImage) {
            $this->pseImageId = $productImage->getId();
        }
    }
}
