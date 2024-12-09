<?php

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlexyBundle\Twig\Organisms;

use FlexyBundle\Service\ProductSaleElementsService;
use Propel\Runtime\Exception\PropelException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Model\CartItem as CartItemModel;
use Thelia\Model\CartItemQuery;
use Thelia\Model\ProductImage;
use Thelia\Model\ProductSaleElementsProductImage;
use Thelia\Service\Model\CartService;
use TwigEngine\Service\DataAccess\DataAccessService;

#[AsLiveComponent(template: '@components/Organisms/CartItem/CartItem.html.twig')]
class CartItem
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public ?string $cartItemId = '';

    #[LiveProp]
    public ?array $cartItem = null;

    protected ?CartItemModel $cartItemModel = null;

    #[LiveProp]
    public ?int $imageId = null;

    #[LiveProp]
    public ?array $attributesAv = null;

    #[LiveProp]
    public bool $hide = false;

    public function __construct(
        private DataAccessService $dataAccessService,
        private CartService $cartService,
        private ProductSaleElementsService $pseService
    ) {
    }

    public function mount(string $cartItemId): void
    {
        $this->cartItemId = $cartItemId;
        $this->cartItemModel = CartItemQuery::create()->findPk($cartItemId);
        $this->setCartItem($cartItemId);
        $this->setImage();
        $this->setAttributesAv();
    }

    public function getCartItem()
    {
        return $this->cartItem;
    }

    public function getImageId()
    {
        return $this->imageId;
    }

    public function getAttributesAv(): ?array
    {
        return $this->attributesAv;
    }

    public function setAttributesAv(): void
    {
        $this->attributesAv = $this->pseService->getAttributesAvFromPse($this->cartItemModel->getProductSaleElements());
    }

    public function setCartItem()
    {
        if (null !== $this->cartItem) {
            return;
        }

        if ('' === $this->cartItemId) {
            return null;
        }
        $this->cartItem = $this->dataAccessService->resources('/api/front/cart_items/'.$this->cartItemId);
    }

    /**
     * @throws PropelException
     */
    private function setImage(): void
    {
        /** @var ProductSaleElementsProductImage $pseImage */
        $pseImage = $this->cartItemModel->getProductSaleElements()->getProductSaleElementsProductImages()->getFirst();

        if ($pseImage) {
            $this->imageId = $pseImage->getProductImageId();

            return;
        }
        /** @var ProductImage $productImage */
        $productImage = $this->cartItemModel->getProduct()->getProductImages()->getFirst();

        if ($productImage) {
            $this->imageId = $productImage->getId();
        }
    }

    #[LiveAction]
    public function setQuantity(#[LiveArg] ?int $quantity = 1): void
    {
        if ($quantity < 2) {
            $quantity = 1;
        }
        $this->cartService->changeItem($this->cartItemId, $quantity);

        $this->cartItem['quantity'] = $quantity;
        $this->emit('resetSummary');
    }

    #[LiveAction]
    public function removeCartItem(): void
    {
        $this->hide = true;
        $this->emit('removeCartItem', [
          'id' => (int) $this->cartItemId,
          'title' => $this->cartItem['product']['i18ns']['title'],
          'imageId' => $this->imageId,
        ]);
    }

    #[LiveListener('cancelDelete')]
    public function showCartItem(): void
    {
        $this->hide = false;
    }
}
