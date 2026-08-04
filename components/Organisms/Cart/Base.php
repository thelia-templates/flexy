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
use Propel\Runtime\Map\TableMap;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Cart\DTO\CartItemAddDTO;
use Thelia\Domain\Cart\DTO\CartItemDeleteDTO;
use Thelia\Domain\Cart\DTO\CartItemUpdateQuantityDTO;
use Thelia\Form\Definition\FrontForm;
use Thelia\Model\ConfigQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElementsQuery;

#[AsLiveComponent]
class Base
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    public ?array $pendingDelete = null;

    /** @var CartItemDto[] */
    #[LiveProp(writable: true)]
    public array $items = [];

    public bool $itemHasNoStockMessage = false;

    public function __construct(
        private readonly CartFacade $cartFacade,
        private readonly FormServiceInterface $formService,
    ) {
    }

    public function mount(): void
    {
        $this->fetchCart();
    }

    #[LiveListener('cross_selling_add_to_cart')]
    public function sync(): void
    {
        $this->fetchCart();
    }

    public function fetchCart(): void
    {
        $this->items = [];
        $cart = $this->cartFacade->getCartFromSession();

        if (null === $cart) {
            return;
        }

        foreach ($cart->getCartItems()->toArray(null, false, TableMap::TYPE_CAMELNAME) as $item) {
            $pse = ProductSaleElementsQuery::create()->findOneById($item['productSaleElementsId']);

            if (null === $pse) {
                continue;
            }

            $stockManaged = ConfigQuery::checkAvailableStock() && 0 === $pse->getProduct()->getVirtual();

            $this->items[] = CartItemDto::fromArray([
                ...$item,
                'stock' => (int) $pse->getQuantity(),
                'stockManaged' => $stockManaged,
                'title' => $pse->getProduct()->getTitle(),
            ]);

            if ($stockManaged && $pse->getQuantity() <= 0) {
                $this->itemHasNoStockMessage = true;
            }
        }
    }

    public function getTotalItems(): int
    {
        return array_sum(array_map(static fn (CartItemDto $item): int => $item->quantity, $this->items));
    }

    private function findCartItemByIndex(int $index): ?CartItemDto
    {
        return $this->items[$index] ?? null;
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

        if (0 === $newQuantity) {
            $this->remove($index);

            return;
        }

        $this->cartFacade->updateItemQuantity(new CartItemUpdateQuantityDTO(
            cart: $this->cartFacade->getOrCreateFromSession(),
            cartItemId: $cartItem->id,
            quantity: $newQuantity,
        ));
        $this->fetchCart();
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
        $this->fetchCart();
        $this->emit(CheckoutEvents::UPDATE_ITEM_QUANTITY_EVENT);
    }

    #[LiveAction]
    public function remove(#[LiveArg] int $index): void
    {
        $match = $this->findCartItemByIndex($index);

        if (null === $match) {
            return;
        }

        $this->pendingDelete = [
            'title' => $match->title,
            'productId' => $match->productId,
            'pseId' => $match->productSaleElementsId,
            'quantity' => $match->quantity,
            'imageId' => $this->resolveImageId($match->productId, $match->productSaleElementsId),
        ];

        $this->cartFacade->removeItem(new CartItemDeleteDTO(
            cart: $this->cartFacade->getOrCreateFromSession(),
            cartItemId: $match->id,
        ));
        $this->fetchCart();
        $this->emit(CheckoutEvents::DELETE_ITEM_EVENT);
    }

    #[LiveAction]
    public function restoreCartItem(#[LiveArg] int $pseId, #[LiveArg] int $productId, #[LiveArg] ?int $quantity = null): void
    {
        if (!$pseId || !$productId) {
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
            'quantity' => $quantity ?? 1,
            'append' => 1,
            'newness' => 0,
        ]);

        $this->cartFacade->addItem(new CartItemAddDTO(
            cart: $cart,
            productId: $productId,
            productSaleElementId: $pseId,
            quantity: $quantity ?? 1,
        ));

        if ($this->pendingDelete && $this->pendingDelete['pseId'] === $pseId) {
            $this->pendingDelete = null;
        }

        $this->fetchCart();
        $this->emit(CheckoutEvents::ADD_ITEM_EVENT, ['pseId' => $pseId]);
    }

    private function resolveImageId(int $productId, int $pseId): ?int
    {
        $pseImage = ProductSaleElementsQuery::create()
            ->findOneById($pseId)
            ?->getProductSaleElementsProductImages()
            ->getFirst();

        if (null !== $pseImage) {
            return $pseImage->getProductImageId();
        }

        return ProductQuery::create()
            ->findOneById($productId)
            ?->getProductImages()
            ->getFirst()
            ?->getId();
    }
}
