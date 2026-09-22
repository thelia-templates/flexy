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

namespace FlexyBundle\Components\Organisms\Summary;

use FlexyBundle\Event\CheckoutEvents;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Api\Service\DataAccess\AttributeAccessService;

#[AsLiveComponent]
class Checkout
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    public function __construct(
        private readonly AttributeAccessService $attributeAccessService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[LiveListener(CheckoutEvents::DELETE_ITEM_EVENT)]
    #[LiveListener(CheckoutEvents::UPDATE_ITEM_QUANTITY_EVENT)]
    #[LiveListener(CheckoutEvents::ADD_ITEM_EVENT)]
    #[LiveListener('syncSummary')]
    public function getSummary(): array
    {
        return [
            'item_count' => $this->attributeAccessService->attributeCart('item_count'),
            'raw_taxed_total_price' => $this->attributeAccessService->attributeCart('raw_taxed_total_price'),
            'total_taxed_price' => $this->attributeAccessService->attributeCart('total_taxed_price'),
            'total_tax_amount' => $this->attributeAccessService->attributeCart('total_tax_amount'),
            'taxed_postage' => $this->attributeAccessService->attributeCart('taxed_postage'),
            'taxed_discount' => $this->attributeAccessService->attributeCart('taxed_discount'),
            'discount' => $this->attributeAccessService->attributeCart('discount'),
            'gift_wrapping_title' => $this->attributeAccessService->attributeCart('gift_wrapping_title'),
            'taxed_gift_wrapping' => $this->attributeAccessService->attributeCart('taxed_gift_wrapping'),
            'discounts' => $this->readDiscounts(),
            'coupons' => $this->attributeAccessService->attributeCoupon('coupon_list'),
        ];
    }

    /**
     * One line per promotion the cart kept — a code the customer typed or a promotion the
     * shop applies on its own — so the summary says what each of them takes off rather than
     * one unexplained total.
     *
     * A core that predates automatic promotions answers an attribute it does not know with
     * an empty string: the shape is checked, and the template falls back to the single
     * global line.
     *
     * A promotion taking nothing off — free shipping, a gift that ran out — carries no
     * figure and gets no line.
     *
     * @return list<array{label: string, taxed_amount: float}>
     */
    private function readDiscounts(): array
    {
        $discounts = $this->attributeAccessService->attributeCart('discounts');

        if (!\is_array($discounts)) {
            return [];
        }

        $lines = [];
        $namedTotal = 0.0;

        foreach ($discounts as $discount) {
            if (!\is_array($discount) || !isset($discount['taxed_amount'])) {
                continue;
            }

            $taxedAmount = (float) $discount['taxed_amount'];

            if (0.0 === $taxedAmount) {
                continue;
            }

            $namedTotal += $taxedAmount;

            $lines[] = [
                'label' => trim((string) ($discount['label'] ?? '')),
                'taxed_amount' => $taxedAmount,
            ];
        }

        if ([] === $lines) {
            return [];
        }

        // Belt: the core prorates these amounts onto the discount the cart actually charges,
        // so they add up. If they ever stop adding up, the summary shows the one global line
        // rather than a set of figures that contradicts the total the shopper pays.
        $taxedDiscount = (float) $this->attributeAccessService->attributeCart('taxed_discount');

        if (abs($namedTotal - $taxedDiscount) > 0.01) {
            return [];
        }

        return $lines;
    }

    /**
     * Whether the cart carries a gift wrapping to state on its own line.
     *
     * Read on the wording rather than on the amount: a wrapping the shop offers is
     * charged nothing and still belongs on the summary, because the buyer asked for it
     * and has to see that it was taken into account.
     *
     * A core that predates the feature answers an attribute it does not know with an
     * empty string, which reads as no wrapping — the theme and the core ship apart.
     */
    public function hasGiftWrapping(): bool
    {
        $title = $this->attributeAccessService->attributeCart('gift_wrapping_title');

        return \is_string($title) && '' !== $title;
    }

    public function hasTax(): bool
    {
        $taxAmount = $this->attributeAccessService->attributeCart('total_tax_amount');

        return $taxAmount !== null && $taxAmount > 0;
    }

    public function hasDiscount(): bool
    {
        $discount = $this->attributeAccessService->attributeCart('taxed_discount');

        return $discount !== null && $discount > 0;
    }
}
