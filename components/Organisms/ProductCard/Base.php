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

use FlexyBundle\DTO\DisplayedPrice;
use FlexyBundle\DTO\ProductDTO;
use FlexyBundle\DTO\ProductSaleElementDTO;
use FlexyBundle\Service\PriceDisplay;
use FlexyBundle\Service\ProductImageResolver;
use FlexyBundle\Service\RunningSaleResolver;
use FlexyBundle\Service\TaxDisplay;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PreMount;
use Thelia\Api\Service\DataAccess\DataAccessService;

#[AsTwigComponent]
class Base extends AbstractProductCard
{
    public ?int $productId = null;

    /**
     * The display the price is drawn in. The visitor's own when not given: it is the group of the
     * customer that decides, and a caller only names one to show the other (the toolkit, a mail).
     */
    public ?TaxDisplay $display = null;
    private ?float $price = null;
    private ?float $promoPrice = null;
    private ?DisplayedPrice $displayedPrice = null;
    private ?DisplayedPrice $displayedPromoPrice = null;
    private bool $isPromo = false;
    private bool $displayInitialPrice = true;
    private bool $isNew = false;

    /** @var RunningSaleResolver::forProduct()'s return type */
    private ?array $runningSaleTag = null;

    public function __construct(
        DataAccessService $dataAccessService,
        ProductImageResolver $productImageResolver,
        private readonly PriceDisplay $priceDisplay,
        private readonly RunningSaleResolver $runningSaleResolver,
    ) {
        parent::__construct($dataAccessService, $productImageResolver);
    }

    #[PreMount]
    public function preMount(?array $data): void
    {
        if (isset($data['productId']) && $data['productId']) {
            $this->productId = (int) $data['productId'];
        }
    }

    public function mount(ProductDTO|array|null $product = null): void
    {
        $this->loadProduct($product, $this->productId);

        if ($this->product === null) {
            return;
        }

        $this->loadProductImageId();
        $this->runningSaleTag = $this->runningSaleResolver->forProduct($this->product->id);

        $defaultPse = $this->findDefaultPse($this->product->productSaleElements);

        if ($defaultPse instanceof ProductSaleElementDTO) {
            // The front API publishes the price of the visitor with and without its taxes, discounts
            // of the group and of the customer included: the display of the group decides which
            // figure is printed, the product page reads the same two.
            $display = $this->display ?? $this->priceDisplay->forVisitor();
            $prices = $defaultPse->productPrices[0];
            $this->price = $prices->price;
            $this->promoPrice = $prices->promoPrice;
            $this->displayedPrice = $this->priceDisplay->ofProduct($display, $prices->price, $prices->taxedPrice);
            $this->displayedPromoPrice = $this->priceDisplay->ofProduct($display, $prices->promoPrice, $prices->taxedPromoPrice) ?? $this->displayedPrice;
            $this->isPromo = $defaultPse->promo;
            $this->displayInitialPrice = $defaultPse->displayInitialPrice;
            $this->isNew = $defaultPse->newness;
        }
    }

    /**
     * The average of the accepted reviews, or null for a product that has none. Read from the
     * product the listing already loaded, so a page of forty cards costs no query of its own:
     * the two fields travel in the product payload.
     */
    public function getRate(): ?float
    {
        return $this->product?->ratingAverage;
    }

    public function getReviewCount(): int
    {
        return $this->product?->ratingCount ?? 0;
    }

    public function getPrice()
    {
        return $this->price;
    }

    public function getPromoPrice()
    {
        return $this->promoPrice;
    }

    /**
     * The catalog price of the product, as the visitor reads it: with or without the taxes.
     */
    public function getDisplayedPrice(): ?DisplayedPrice
    {
        return $this->displayedPrice;
    }

    public function getDisplayedPromoPrice(): ?DisplayedPrice
    {
        return $this->displayedPromoPrice;
    }

    public function getIsPromo()
    {
        return $this->isPromo;
    }

    /**
     * Whether the catalog price is shown struck through, with the discount badge, next to the
     * price charged. A price that differs from the catalog one without being a promotion (a
     * price per sales unit, say) is shown alone.
     */
    public function getShowsInitialPrice(): bool
    {
        return $this->isPromo && $this->displayInitialPrice;
    }

    public function getIsNew()
    {
        return $this->isNew;
    }

    /**
     * The running-sale label the product carries, or null when no active operation
     * asked to show one on it. Comes from a per-request map (RunningSaleResolver), so
     * a listing of N cards costs one collection read, not N.
     *
     * @return array{saleLabel: string, shouldDisplayCountdown: bool, countdownRemainingSeconds: int|null, publicUrl: string|null}|null
     */
    public function getRunningSaleTag(): ?array
    {
        return $this->runningSaleTag;
    }

    public function getPromoRate(): float
    {
        if ($this->getShowsInitialPrice()) {
            return (($this->price - $this->promoPrice) / $this->price) * -1;
        }

        return 0;
    }

    /**
     * @var pseList ProductSaleElementDTO[]
     */
    private function findDefaultPse($pseList): ?ProductSaleElementDTO
    {
        if ($pseList) {
            foreach ($pseList as $pse) {
                if ($pse->isDefault) {
                    return $pse;
                }
            }
        }

        return null;
    }
}
