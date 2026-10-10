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

namespace FlexyBundle\Components\Organisms\Cart;

use FlexyBundle\DTO\CartItemDto;
use FlexyBundle\Event\CheckoutEvents;
use FlexyBundle\Service\CartStockService;
use Propel\Runtime\Map\TableMap;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Api\Service\DataAccess\AttributeAccessService;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Cart\DTO\CartItemAddDTO;
use Thelia\Domain\Cart\DTO\CartItemDeleteDTO;
use Thelia\Domain\Cart\DTO\CartItemUpdateQuantityDTO;
use Thelia\Form\Definition\FrontForm;
use Thelia\Model\CartItemQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductSaleElementsProductImageQuery;
use Thelia\Model\ProductSaleElementsQuery;

#[AsLiveComponent]
class Base
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    /**
     * The tag this step's title is written with.
     *
     * A page that stacks the whole tunnel carries three of these organisms at once, and
     * three h1 tell a screen reader it is looking at three documents: that page hands
     * over 'h2'. A checkout of one screen per step keeps the h1 it has always had.
     */
    #[LiveProp]
    public string $headingLevel = 'h1';

    /**
     * The line just removed, kept for the "undo" offer. A LiveProp so that it comes back signed with the next
     * request: the undo can only put back what this component removed, in the quantity it removed.
     *
     * @var array{title: string, productId: int, pseId: int, quantity: int|float, imageId: int|null}|null
     */
    #[LiveProp]
    public ?array $pendingDelete = null;

    /** @var CartItemDto[] */
    #[LiveProp(writable: true)]
    public array $items = [];

    public bool $itemHasNoStockMessage = false;

    public bool $itemHasInsufficientStockMessage = false;

    /**
     * Labels of the promotions the shop could not apply to this cart — the gift they carry
     * is out of stock. Published by the core; the theme only prints them.
     *
     * @var list<string>
     */
    public array $unavailablePromotionMessages = [];

    public function __construct(
        private readonly CartFacade $cartFacade,
        private readonly FormServiceInterface $formService,
        private readonly CartStockService $cartStockService,
        private readonly AttributeAccessService $attributeAccessService,
    ) {
    }

    public function mount(): void
    {
        $this->fetchCart();
    }

    #[LiveListener('cross_selling_add_to_cart')]
    #[LiveListener(CheckoutEvents::ADD_ITEM_EVENT)]
    public function sync(): void
    {
        $this->fetchCart();
    }

    /**
     * `items` is a LiveProp, so a re-render hydrates it from the DOM and mount() never runs
     * again: without this the component would redisplay whatever the browser still held —
     * stale quantities, stale stock — which is exactly what the focus resync must avoid.
     */
    #[PreReRender]
    public function refreshBeforeRender(): void
    {
        $this->fetchCart();
    }

    public function fetchCart(): void
    {
        $this->items = [];
        $this->itemHasNoStockMessage = false;
        $this->itemHasInsufficientStockMessage = false;
        $this->unavailablePromotionMessages = [];
        $cart = $this->cartFacade->getCartFromSession();

        if (null === $cart) {
            return;
        }

        $this->unavailablePromotionMessages = $this->readUnavailablePromotions();
        $offeredDiscounts = $this->readOfferedLineDiscounts();

        // TYPE_CAMELNAME, so `is_offered` reaches the DTO as `isOffered` along with every
        // other column of the line.
        foreach ($cart->getCartItems()->toArray(null, false, TableMap::TYPE_CAMELNAME) as $item) {
            $pse = ProductSaleElementsQuery::create()->findOneById($item['productSaleElementsId']);

            if (null === $pse) {
                continue;
            }

            $stockManaged = $this->cartStockService->isStockManaged($pse);

            $cartItem = CartItemDto::fromArray([
                ...$item,
                // toArray() dumps any relation a previous evaluation happened to load on the
                // line, and a dumped product does not survive LiveComponent dehydration.
                // This path never carried one: the line is priced by its own columns.
                'product' => null,
                'stock' => (int) $pse->getQuantity(),
                'stockManaged' => $stockManaged,
                'title' => $pse->getProduct()->getTitle(),
                'offeredTaxedDiscount' => $offeredDiscounts[(int) $item['id']] ?? 0.0,
            ]);

            $this->items[] = $cartItem;

            if ($this->cartStockService->isOutOfStock($cartItem)) {
                $this->itemHasNoStockMessage = true;
            } elseif ($this->cartStockService->isInsufficient($cartItem)) {
                $this->itemHasInsufficientStockMessage = true;
            }
        }
    }

    /**
     * A core that predates automatic promotions answers an attribute it does not know with
     * an empty string, so the shape is checked rather than assumed.
     *
     * @return list<string>
     */
    private function readUnavailablePromotions(): array
    {
        $labels = $this->attributeAccessService->attributeCart('unavailable_promotions');

        if (!\is_array($labels)) {
            return [];
        }

        $messages = [];

        foreach ($labels as $label) {
            if (!\is_scalar($label)) {
                continue;
            }

            $label = trim((string) $label);

            if ('' !== $label) {
                $messages[] = $label;
            }
        }

        return $messages;
    }

    /**
     * What each offered line costs the shop, taxes included, indexed by the id of the
     * line — the figure the cart page strikes the gift's price with.
     *
     * The lines come from the model above, which only carries columns; this one is not a
     * column, it is what the promotion owning the line takes off the cart, and only the
     * core can price it. A core that predates automatic promotions publishes nothing here
     * and every line reads as undiscounted, which is what the template falls back to.
     *
     * @return array<int, float>
     */
    private function readOfferedLineDiscounts(): array
    {
        $items = $this->attributeAccessService->attributeCart('cart_items');

        if (!\is_array($items)) {
            return [];
        }

        $discounts = [];

        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $id = (int) ($item['id'] ?? $item['ID'] ?? 0);
            $discount = $item['offered_taxed_discount'] ?? $item['offeredTaxedDiscount'] ?? null;

            if (0 === $id || !is_numeric($discount)) {
                continue;
            }

            $discounts[$id] = (float) $discount;
        }

        return $discounts;
    }

    public function getTotalItems(): int
    {
        return array_sum(array_map(static fn (CartItemDto $item): int => $item->quantity, $this->items));
    }

    /**
     * The line a quantity or delete action may act on.
     *
     * An offered line belongs to the promotion, not to the buyer: the template hides its
     * controls, but the index still travels in the request and a replayed action names any
     * line it likes. The core refuses the write too; this guard puts the refusal where the
     * action is read.
     */
    private function findCartItemByIndex(int $index): ?CartItemDto
    {
        $cartItem = $this->items[$index] ?? null;

        if (null === $cartItem || $cartItem->isOffered) {
            return null;
        }

        return $cartItem;
    }

    #[LiveAction]
    public function onQuantityChanged(#[LiveArg] int $index, #[LiveArg] int $baseQuantity): void
    {
        $cartItem = $this->findCartItemByIndex($index);

        if (null === $cartItem) {
            return;
        }

        // Quantity already updated on the DTO by the data-model binding.
        $newQuantity = $cartItem->quantity;

        if ($newQuantity <= 0) {
            $this->remove($index);

            return;
        }

        if ($newQuantity > $baseQuantity) {
            $this->plus($index, $newQuantity);
        } elseif ($newQuantity < $baseQuantity) {
            $this->minus($index, $newQuantity);
        }
    }

    #[LiveAction]
    public function minus(#[LiveArg] int $index, ?int $quantity = null): void
    {
        $cartItem = $this->findCartItemByIndex($index);

        if (null === $cartItem) {
            return;
        }

        $newQuantity = max(0, $quantity ?? $cartItem->quantity - 1);

        // The core rejects any quantity still above the remaining stock
        // (Thelia\Model\CartItem::updateQuantity), so a line that already exceeds it could not be
        // decremented at all: every intermediate step was refused and the visitor stayed stuck on
        // the very quantity the cart asks them to lower.
        if ($cartItem->stockManaged && $newQuantity > $cartItem->stock) {
            $newQuantity = $cartItem->stock;
        }

        if (0 === $newQuantity) {
            $this->remove($index);

            return;
        }

        $this->cartFacade->updateItemQuantity(new CartItemUpdateQuantityDTO(
            cart: $this->cartFacade->getOrCreateFromSession(),
            cartItemId: $cartItem->id,
            quantity: $newQuantity,
        ));
        $this->emit(CheckoutEvents::UPDATE_ITEM_QUANTITY_EVENT);
    }

    #[LiveAction]
    public function plus(#[LiveArg] int $index, ?int $quantity = null): void
    {
        $cartItem = $this->findCartItemByIndex($index);

        if (null === $cartItem) {
            return;
        }

        $maxQuantity = $cartItem->stockManaged ? $cartItem->stock : \PHP_INT_MAX;
        $newQuantity = min($maxQuantity, $quantity ?? $cartItem->quantity + 1);

        $this->cartFacade->updateItemQuantity(new CartItemUpdateQuantityDTO(
            cart: $this->cartFacade->getOrCreateFromSession(),
            cartItemId: $cartItem->id,
            quantity: $newQuantity,
        ));
        $this->emit(CheckoutEvents::UPDATE_ITEM_QUANTITY_EVENT);
    }

    #[LiveAction]
    public function remove(#[LiveArg] int $index): void
    {
        $match = $this->findCartItemByIndex($index);

        if (null === $match) {
            return;
        }

        // `items` is writable from the browser: the declination and the quantity to put back are read from the
        // line stored in the cart of the session, only the title comes from the item.
        $cart = $this->cartFacade->getOrCreateFromSession();
        $line = CartItemQuery::create()->filterByCartId($cart->getId())->filterById($match->id)->findOne();

        if (null === $line) {
            return;
        }

        $this->pendingDelete = [
            'title' => $match->title,
            'productId' => $line->getProductId(),
            'pseId' => $line->getProductSaleElementsId(),
            'quantity' => (int) $line->getQuantity(),
            'imageId' => $this->resolveImageId($line->getProductId(), $line->getProductSaleElementsId()),
        ];

        $this->cartFacade->removeItem(new CartItemDeleteDTO(
            cart: $cart,
            cartItemId: $line->getId(),
        ));
        $this->emit(CheckoutEvents::DELETE_ITEM_EVENT);
    }

    #[LiveAction]
    public function restoreCartItem(#[LiveArg] int $pseId, #[LiveArg] int $productId, #[LiveArg] ?int $quantity = null): void
    {
        // Only the line this component removed comes back, in the quantity it had: the arguments come from the
        // browser, the pending removal is the component's own signed state.
        if (!$pseId || !$productId || null === $this->pendingDelete
            || $this->pendingDelete['pseId'] !== $pseId || $this->pendingDelete['productId'] !== $productId) {
            return;
        }

        $quantity = max(1, min($quantity ?? (int) $this->pendingDelete['quantity'], (int) $this->pendingDelete['quantity']));

        // The cart form is replayed below for its side effects only (its CSRF token cannot be given from a live
        // action), so the stock rule it holds is applied here: a line is not put back above what is left.
        if ($this->isShortOfStock($pseId, $quantity)) {
            $this->pendingDelete = null;

            return;
        }

        $cart = $this->cartFacade->getOrCreateFromSession();

        // Replay the front cart form build/submit for its side effects (module
        // form events). Its validity is NOT checked: the CSRF token cannot be
        // provided from a live action, so the form always reports invalid.
        $form = $this->formService->getFormByName(FrontForm::CART_ADD);
        $form->submit([
            'product' => $productId,
            'product_sale_elements_id' => $pseId,
            'quantity' => $quantity,
            'append' => 1,
            'newness' => 0,
        ]);

        $this->cartFacade->addItem(new CartItemAddDTO(
            cart: $cart,
            productId: $productId,
            productSaleElementId: $pseId,
            quantity: $quantity,
        ));

        $this->pendingDelete = null;

        $this->emit(CheckoutEvents::ADD_ITEM_EVENT, ['pseId' => $pseId]);
    }

    private function isShortOfStock(int $pseId, int $quantity): bool
    {
        if (!ConfigQuery::checkAvailableStock()) {
            return false;
        }

        $productSaleElements = ProductSaleElementsQuery::create()->findPk($pseId);

        return null === $productSaleElements
            || (0 === $productSaleElements->getProduct()->getVirtual() && $productSaleElements->getQuantity() < $quantity);
    }

    /**
     * getImages() renders visible images only, so an image hidden by the merchant must
     * not win the slot here: it would come back as the placeholder.
     */
    private function resolveImageId(int $productId, int $pseId): ?int
    {
        $pseImageId = ProductSaleElementsProductImageQuery::create()
            ->filterByProductSaleElementsId($pseId)
            ->useProductImageQuery()
                ->filterByVisible(true)
                ->orderByPosition()
            ->endUse()
            ->findOne()
            ?->getProductImageId();

        if (null !== $pseImageId) {
            return $pseImageId;
        }

        return ProductImageQuery::create()
            ->filterByProductId($productId)
            ->filterByVisible(true)
            ->orderByPosition()
            ->findOne()
            ?->getId();
    }
}
