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

namespace FlexyBundle\Components\Organisms\CartItem;

use FlexyBundle\DTO\CartItemDto;
use FlexyBundle\Service\CartStockService;
use FlexyBundle\Service\ProductSaleElementsService;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Taxation\TaxEngine\TaxEngine;
use Thelia\Model\CartItemQuery;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductSaleElementsProductImageQuery;

#[AsTwigComponent]
class Base
{
    public CartItemDto $cartItem;
    public bool $outOfStock = false;
    public bool $insufficientStock = false;
    public bool $promo = false;
    public bool $isOffered = false;
    /** The promotion owning this line takes something off the cart because of it. */
    public bool $offeredIsDiscounted = false;
    public ?int $pseImageId = null;
    public array $prices = [];
    public string $title = '';
    public ?string $desc = '';
    public string $url = '';
    public array $attributesAv = [];

    public function __construct(
        private readonly ProductSaleElementsService $pseService,
        private readonly TaxEngine $taxEngine,
        private readonly LangService $langService,
        private readonly CartStockService $cartStockService,
    ) {
    }

    public function mount(CartItemDto $cartItem): void
    {
        $this->cartItem = $cartItem;

        // Read before the model lookup: a line the shop put in the cart still renders as
        // offered even when nothing else about it can be resolved.
        $this->isOffered = $cartItem->isOffered;

        if ($this->isOffered) {
            // Set before anything is priced: the early return below still leaves a line
            // that renders as offered, and it must not print a figure that is not there.
            $this->prices['offeredTaxedPrice'] = 0.0;
            $this->prices['offeredEffectiveTaxedPrice'] = 0.0;
        }

        $cartItemModel = CartItemQuery::create()->findPk($cartItem->id);

        if (null === $cartItemModel) {
            return;
        }

        $pse = $cartItemModel->getProductSaleElements();
        $product = $cartItemModel->getProduct();
        $taxCountry = $this->taxEngine->getDeliveryCountry();

        $this->prices = [
            'taxedPrice' => $cartItemModel->getTaxedPrice($taxCountry),
            'promoTaxedPrice' => $cartItemModel->getTaxedPromoPrice($taxCountry),
        ];

        if ($this->isOffered) {
            // The whole line, not one unit: the promotion prices what it gives away by the
            // line, and a gift given by twos is struck and charged as one figure.
            $offeredTotal = $cartItemModel->getTotalRealTaxedPrice($taxCountry);
            // A discount worth more than the line is a discount worth the line: the shopper
            // is never shown a negative price, whatever the promotion was configured with.
            $offeredDiscount = min(max($cartItem->offeredTaxedDiscount, 0.0), $offeredTotal);

            // Half a cent: below it the two figures print the same, and striking a price to
            // show the same price again says nothing.
            $this->offeredIsDiscounted = $offeredDiscount >= 0.005;

            $this->prices['offeredTaxedPrice'] = $offeredTotal;
            $this->prices['offeredEffectiveTaxedPrice'] = $offeredTotal - $offeredDiscount;
        }

        $this->promo = (bool) $cartItemModel->getPromo();
        $this->title = $product->getTitle();
        $this->desc = $product->getChapo();
        $this->url = $product->getUrl($this->langService->getLocale());
        $this->outOfStock = $this->cartStockService->isOutOfStock($cartItem);
        $this->insufficientStock = $this->cartStockService->isInsufficient($cartItem);
        $this->attributesAv = $this->pseService->getAttributesAvFromPse($pse);

        // getImages() renders visible images only, so an image hidden by the merchant must
        // not win the slot here: it would come back as the placeholder.
        $pseImageId = ProductSaleElementsProductImageQuery::create()
            ->filterByProductSaleElementsId($pse->getId())
            ->useProductImageQuery()
                ->filterByVisible(true)
                ->orderByPosition()
            ->endUse()
            ->findOne()
            ?->getProductImageId();

        if (null !== $pseImageId) {
            $this->pseImageId = $pseImageId;

            return;
        }

        $this->pseImageId = ProductImageQuery::create()
            ->filterByProductId($product->getId())
            ->filterByVisible(true)
            ->orderByPosition()
            ->findOne()
            ?->getId();
    }
}
