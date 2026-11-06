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

namespace FlexyBundle\Components\Organisms\AddToCartToast;

use FlexyBundle\DTO\QuantityRuleDTO;
use FlexyBundle\Service\CartQuantity;
use FlexyBundle\Service\ProductSaleElementsService;
use FlexyBundle\Service\SaleElementQuantityRules;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Domain\Localization\LocalizationFacade;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductSaleElementsProductImageQuery;
use Thelia\Model\ProductSaleElementsQuery;

#[AsLiveComponent]
class Base
{
    use DefaultActionTrait;

    /** In the unit the sale element is sold in: 1.25 for a kilogram and a quarter. */
    #[LiveProp]
    public ?float $quantity = null;

    /** The decimals and the unit the quantity is written with: "1,25 kg". */
    #[LiveProp]
    public int $quantityDecimals = 0;

    #[LiveProp]
    public ?string $unitCode = null;

    #[LiveProp]
    public ?int $pseId = null;

    #[LiveProp]
    public ?string $title = null;

    #[LiveProp]
    public ?string $secondaryTitle = null;

    #[LiveProp]
    public ?string $productUrl = null;

    #[LiveProp]
    public ?int $imageId = null;

    #[LiveProp]
    public array $attributesAv = [];

    public function __construct(
        private readonly ProductSaleElementsService $pseService,
        private readonly LocalizationFacade $localizationFacade,
        // Optional so that a child theme calling this constructor keeps working: the quantity
        // is then written without its unit.
        private readonly ?SaleElementQuantityRules $quantityRules = null,
    ) {
    }

    #[LiveListener('addToCart')]
    public function addToCart(#[LiveArg] array $values): void
    {
        // `$values` travels back through the browser, so the keys are not guaranteed to be there.
        $pse = ProductSaleElementsQuery::create()->findPk((int) ($values['product_sale_elements_id'] ?? 0));

        if ($pse === null) {
            return;
        }

        $locale = $this->localizationFacade->getCurrentLocale();
        $product = $pse->getProduct()->setLocale($locale);

        // As the shopper typed it, "1,25" or "1.25"; a quantity that reads as nothing shows as one.
        $quantity = CartQuantity::typed($values['quantity'] ?? null) ?? 0.0;
        $this->quantity = $quantity > 0.0 ? $quantity : 1.0;
        $rule = QuantityRuleDTO::fromArray($this->quantityRules?->ofSaleElement($pse) ?? []);
        $this->quantityDecimals = $rule->decimals;
        $this->unitCode = $rule->unitCode;
        $this->pseId = $pse->getId();
        $this->title = $product->getTitle();
        $this->secondaryTitle = $product->getChapo();
        $this->productUrl = $product->getUrl($locale);
        $this->attributesAv = $this->pseService->getAttributesAvFromPse($pse);

        // A PSE-specific visual when it has one, the product's first visual otherwise.
        // getImages() renders visible images only, so a hidden one must not win the slot
        // here: it would come back as the placeholder.
        $this->imageId = ProductSaleElementsProductImageQuery::create()
            ->filterByProductSaleElementsId($this->pseId)
            ->useProductImageQuery()
                ->filterByVisible(true)
                ->orderByPosition()
            ->endUse()
            ->findOne()
            ?->getProductImageId()
            ?? ProductImageQuery::create()
                ->filterByProductId($product->getId())
                ->filterByVisible(true)
                ->orderByPosition()
                ->findOne()
                ?->getId();
    }

    #[LiveAction]
    public function closeToast(): void
    {
        $this->quantity = null;
        $this->quantityDecimals = 0;
        $this->unitCode = null;
        $this->pseId = null;
        $this->title = null;
        $this->secondaryTitle = null;
        $this->productUrl = null;
        $this->imageId = null;
        $this->attributesAv = [];
    }
}
