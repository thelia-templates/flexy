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
use FlexyBundle\Service\BundleLines;
use FlexyBundle\Service\CartStockService;
use FlexyBundle\Service\ProductSaleElementsService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;
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
    /** What tells the shopper this pack can no longer be ordered as it is; empty for any line the order can take. */
    public string $unorderableMessage = '';

    public function __construct(
        private readonly ProductSaleElementsService $pseService,
        private readonly TaxEngine $taxEngine,
        private readonly LangService $langService,
        private readonly CartStockService $cartStockService,
        // The `translator` service is the one carrying the theme catalogue, which the autowired interface is not.
        #[Autowire(service: 'translator')]
        private readonly TranslatorInterface $translator,
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
        $locale = $this->langService->getLocale();
        // The model of the line applies the rule of the shop for a product without a translation in this language.
        $product = $cartItemModel->getProduct(null, $locale);
        // Read before the pricing below: it asks the line for its product again, without a locale, which puts the
        // same instance back in the default language.
        $this->title = (string) $product->getTitle();
        $this->desc = $product->getChapo();
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

        if (!$cartItem->bundleOrderable) {
            // The core words the refusal (and translates it); the theme's sentence stands in for a core that does not.
            $this->unorderableMessage = '' !== $cartItem->bundleRefusal
                ? $cartItem->bundleRefusal
                : BundleLines::notOrderableMessage($this->translator, (string) $product->getRef());
        }

        $this->promo = (bool) $cartItemModel->getPromo();
        $this->url = $product->getUrl($locale);
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
