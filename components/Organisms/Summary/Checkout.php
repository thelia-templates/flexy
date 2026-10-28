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

use FlexyBundle\DTO\DisplayedPrice;
use FlexyBundle\Event\CheckoutEvents;
use FlexyBundle\Service\DeliveryDateBridge;
use FlexyBundle\Service\PriceDisplay;
use FlexyBundle\Service\TaxDisplay;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Action\Coupon;
use Thelia\Api\Service\DataAccess\AttributeAccessService;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Localization\Service\LangService;

#[AsLiveComponent]
class Checkout
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    /**
     * Whether the coupon form is drawn above the totals.
     *
     * Live state rather than a template prop: the summary redraws itself on its own
     * listeners (an item added or removed, `syncSummary`), and a template prop is not sent
     * back with those re-renders — a summary mounted without the form showed it again after
     * the first change of the cart.
     */
    #[LiveProp]
    public bool $showPromoCodeForm = true;

    public function __construct(
        private readonly AttributeAccessService $attributeAccessService,
        private readonly Coupon $coupon,
        private readonly DeliveryDateBridge $deliveryDates,
        private readonly CartFacade $cartFacade,
        private readonly LangService $langService,
        private readonly PriceDisplay $priceDisplay,
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
        $this->coupon->reconcileWithVatExemption();

        $display = $this->priceDisplay->forVisitor();

        return [
            // What the buyer reads: each figure is the one of the display of the group, the
            // others stay as they are for the modules that read the summary through the hook.
            'display' => $display,
            'subtotal' => $this->pickFromCart($display, 'raw_total_price', 'raw_taxed_total_price'),
            'postage' => $this->pickFromCart($display, 'untaxed_postage', 'taxed_postage'),
            'gift_wrapping_price' => $this->pickFromCart($display, 'untaxed_gift_wrapping', 'taxed_gift_wrapping'),
            'discount_price' => $this->pickFromCart($display, 'discount', 'taxed_discount'),
            'total' => $this->pickFromCart($display, 'total_price', 'total_taxed_price'),
            // What a buyer shown the prices untaxed reads at the foot of the summary: the cart
            // before its taxes, the taxes (total_tax_amount, below), and what is to pay.
            'total_without_taxes' => $this->pickFromCart(TaxDisplay::Untaxed, 'total_price', 'total_taxed_price'),
            'total_to_pay' => $this->pickFromCart(TaxDisplay::Taxed, 'total_price', 'total_taxed_price'),
            'item_count' => $this->attributeAccessService->attributeCart('item_count'),
            'raw_taxed_total_price' => $this->attributeAccessService->attributeCart('raw_taxed_total_price'),
            'total_taxed_price' => $this->attributeAccessService->attributeCart('total_taxed_price'),
            'total_tax_amount' => $this->attributeAccessService->attributeCart('total_tax_amount'),
            'taxed_postage' => $this->attributeAccessService->attributeCart('taxed_postage'),
            'delivery_module_id' => $this->attributeAccessService->attributeCart('delivery_module_id'),
            'taxed_discount' => $this->attributeAccessService->attributeCart('taxed_discount'),
            'discount' => $this->attributeAccessService->attributeCart('discount'),
            'gift_wrapping_title' => $this->attributeAccessService->attributeCart('gift_wrapping_title'),
            'delivery_date' => $this->deliveryDates->chosenOn($this->cartFacade->getOrCreateFromSession(), $this->langService->getLocale()),
            'taxed_gift_wrapping' => $this->attributeAccessService->attributeCart('taxed_gift_wrapping'),
            'discounts' => $this->readDiscounts($display),
            'coupons' => $this->attributeAccessService->attributeCoupon('coupon_list'),
            'invoice_vat_number' => $this->attributeAccessService->attributeCart('invoice_vat_number'),
            'vat_exemption_state' => $this->attributeAccessService->attributeCart('vat_exemption_state'),
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
     * @return list<array{label: string, taxed_amount: float, amount: float, displayed: DisplayedPrice}>
     */
    private function readDiscounts(TaxDisplay $display): array
    {
        $discounts = $this->attributeAccessService->attributeCart('discounts');

        if (!\is_array($discounts)) {
            return [];
        }

        $lines = [];

        foreach ($discounts as $discount) {
            if (!\is_array($discount) || !isset($discount['taxed_amount'], $discount['amount'])) {
                continue;
            }

            $taxedAmount = (float) $discount['taxed_amount'];

            if (0.0 === $taxedAmount) {
                continue;
            }

            $displayed = $this->priceDisplay->pick($display, (float) $discount['amount'], $taxedAmount);

            $lines[] = [
                'label' => trim((string) ($discount['label'] ?? '')),
                'taxed_amount' => $taxedAmount,
                'amount' => (float) $discount['amount'],
                'displayed' => $displayed,
            ];
        }

        // The core prorates these amounts onto the discount the cart actually charges (the last
        // line takes the remainder), taxed and untaxed alike, so they add up to the discount line
        // of the summary: the theme prints them and does not check the core's sum.
        return $lines;
    }

    /**
     * One figure of the cart, in the display of the buyer: the core publishes the cart totals
     * both with and without the taxes, the display only picks.
     */
    private function pickFromCart(TaxDisplay $display, string $untaxedAttribute, string $taxedAttribute): DisplayedPrice
    {
        return $this->priceDisplay->pick(
            $display,
            (float) $this->attributeAccessService->attributeCart($untaxedAttribute),
            (float) $this->attributeAccessService->attributeCart($taxedAttribute),
        );
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

    public function isVatExempted(): bool
    {
        return (bool) $this->attributeAccessService->attributeCart('is_vat_exempted');
    }

    public function hasDiscount(): bool
    {
        $discount = $this->attributeAccessService->attributeCart('taxed_discount');

        return $discount !== null && $discount > 0;
    }
}
