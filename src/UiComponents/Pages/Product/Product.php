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

namespace FlexyBundle\UiComponents\Pages\Product;

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Cart\DTO\CartItemAddDTO;
use Thelia\Form\Definition\FrontForm;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Api\Service\DataAccess\ProductSaleElementsAccessService;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Model\ConfigQuery;

#[AsLiveComponent(name: 'Flexy:Pages:Product', template: '@UiComponents/Pages/Product/Product.html.twig')]
class Product
{
    use ComponentToolsTrait;
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public array $product;

    #[ExposeInTemplate]
    public ?array $pses = [];

    #[ExposeInTemplate]
    public ?array $availablePses = [];

    #[LiveProp]
    public array $images = [];

    #[LiveProp]
    public ?array $productAttrs = [];

    #[LiveProp]
    public ?array $currentCombination = [];

    #[LiveProp]
    public ?array $currentPse = null;

    #[LiveProp]
    public ?array $initialFormData = null;

    #[LiveProp]
    public ?bool $noAvailablePse = false;

    public function __construct(
        private DataAccessService $dataAccessService,
        private ProductSaleElementsAccessService $pseAccessService,
        private FormServiceInterface $formService,
        private FormFactoryInterface $formFactory,
        private CartFacade $cartFacade,
        private RequestStack $requestStack,
    ) {}

    public function mount(array $product): void
    {
        $this->product = $product;
        $this->productAttrs = $this->pseAccessService->attrAvByProduct($this->product['id']);
        $this->setInitialCurrentPse();
        $this->setImages();
    }

    protected function instantiateForm(): FormInterface
    {
        return $this->formService->getFormByName(FrontForm::CART_ADD, [
            'product' => $this->product['id'],
            'product_sale_elements_id' => $this->currentPse['id'],
            'quantity' => 1,
            'append' => 1,
            'newness' => 0,
        ]);
    }

    public function getPses(): array
    {
        if (0 !== \count($this->pses)) {
            return $this->pses;
        }

        $this->pses = json_decode($this->pseAccessService->psesByProduct($this->product['id']), true);

        return $this->pses;
    }

    #[LiveAction]
    public function getQuantity(#[LiveArg] ?int $quantity = 1)
    {
        $this->formValues['quantity'] = $quantity;

        if ($quantity < 2) {
            $this->formValues['quantity'] = 1;
        }

        $remainingStock = $this->getRemainingStock();
        if ($remainingStock > 0 && $this->formValues['quantity'] > $remainingStock) {
            $this->formValues['quantity'] = $remainingStock;
        }

        return $this->formValues['quantity'];
    }

    public function getCartQuantityForCurrentPse(): int
    {
        if (null === $this->currentPse) {
            return 0;
        }

        $cart = $this->cartFacade->getCartFromSession();
        if (null === $cart) {
            return 0;
        }

        $pseId = (int) $this->currentPse['id'];
        $quantity = 0;
        foreach ($cart->getCartItems() as $cartItem) {
            if ($cartItem->getProductSaleElementsId() === $pseId) {
                $quantity += (int) $cartItem->getQuantity();
            }
        }

        return $quantity;
    }

    public function getRemainingStock(): int
    {
        if (!$this->isStockManaged()) {
            return \PHP_INT_MAX;
        }

        $stock = (int) ($this->currentPse['quantity'] ?? 0);

        return max(0, $stock - $this->getCartQuantityForCurrentPse());
    }

    public function isMaxQuantityReached(): bool
    {
        if (!$this->isStockManaged()) {
            return false;
        }

        $stock = (int) ($this->currentPse['quantity'] ?? 0);

        return $stock > 0 && $this->getRemainingStock() <= 0;
    }

    /**
     * Stock is only enforced front-side when the `check-available-stock` config is enabled
     * and the product is not virtual (mirrors Thelia core CartAdd / OrderFacade logic).
     */
    public function isStockManaged(): bool
    {
        if ($this->product['virtual'] ?? false) {
            return false;
        }

        return ConfigQuery::checkAvailableStock();
    }

    #[LiveAction]
    public function updateCurrentPseFromId(#[LiveArg] ?string $pseIds = null): void
    {
        if (!$pseIds) {
            return;
        }

        try {
            $pses = array_values(array_filter($this->getPses(), function ($pse) use (&$pseIds) {
                return \in_array($pse['id'], explode(',', $pseIds));
            }));
            $this->currentPse = $pses[0];
            $this->currentCombination = $this->currentPse['combination'];
            $this->formValues['product_sale_elements_id'] = $this->currentPse['id'];
            $this->noAvailablePse = false;
        } catch (\Throwable $th) {
            $this->noAvailablePse = true;
        }
    }

    #[LiveAction]
    public function updateCurrentCombination(#[LiveArg] $attr, #[LiveArg] $value): void
    {
        $newCombination = $this->currentCombination;
        $newCombination[$attr] = $value;

        $this->currentCombination = $newCombination;

        $this->updateCurrentPseFromCombination();
    }

    public function updateCurrentPseFromCombination(): void
    {
        $matchingCombinations = array_filter($this->getPses(), fn($pse) => $pse['combination'] === $this->currentCombination);

        try {
            $this->currentPse = reset($matchingCombinations);
            $this->formValues['product_sale_elements_id'] = $this->currentPse['id'];

            $this->dispatchBrowserEvent('change:pse', [
                'pseId' => $this->currentPse['id'],
            ]);
            $this->noAvailablePse = false;
        } catch (\Throwable $th) {
            $this->noAvailablePse = true;
        }
    }

    #[LiveAction]
    public function save(): void
    {
        // Clamp the requested quantity to the remaining stock before validation,
        // so a manually typed value above the stock cannot trigger a 422.
        // getRemainingStock() returns PHP_INT_MAX when stock is not managed.
        $remainingStock = $this->getRemainingStock();
        if ($remainingStock > 0 && (int) ($this->formValues['quantity'] ?? 1) > $remainingStock) {
            $this->formValues['quantity'] = $remainingStock;
        }

        $this->submitForm();
        $formDatas = $this->getForm()->getData();
        $this->cartFacade->addItem(
            new CartItemAddDTO(
                cart: $this->cartFacade->getOrCreateFromSession(),
                productId: (int) $formDatas['product'],
                productSaleElementId: (int) $formDatas['product_sale_elements_id'],
                quantity: (int) $formDatas['quantity'],
                append: (bool) $formDatas['append'],
                newness: (bool) $formDatas['newness'],
            )
        );
        $this->emit('addToCart', [
            'values' => $this->formValues,
        ]);

        $this->dispatchBrowserEvent('addPseToCart', [
            'pse' => $this->formValues['product_sale_elements_id'],
            'quantity' => $this->formValues['quantity'],
        ]);
    }

    private function setInitialCurrentPse(): void
    {
        $pseRef = $this->requestStack->getCurrentRequest()?->query->get('ref');
        $pses = $this->getPses();

        $match = [];

        if ($pseRef) {
            $match = array_values(array_filter($pses, fn($pse) => $pse['ref'] === $pseRef))[0] ?? null;
        }

        if (!$match) {
            $match = array_values(array_filter($pses, fn($pse) => $pse['isDefault'] ?? false))[0] ?? null;
        }

        if ($match) {
            $this->currentPse = $match;
            $this->currentCombination = $match['combination'];
        }
    }

    private function setImages(): void
    {
        $imagesPdt = $this->dataAccessService->resources(
            '/api/front/product_images',
            [
                'product.id' => $this->product['id'],
                'visible' => true,
            ]
        );

        $images = $this->dataAccessService->resources(
            '/api/front/product_sale_elements_product_image',
            [
                'productImageId' => array_map(static fn($img) => $img['id'], $imagesPdt),
                'productSaleElements.product.id' => $this->product['id'],
                'visible' => true,
            ]
        );

        $psesImgs = $this->getUniquePseImg($images, $imagesPdt);

        $productImgs = array_filter($imagesPdt, static function ($img) use ($images) {
            foreach ($images as $pseImg) {
                if ($pseImg['productImageId'] === $img['id']) {
                    return false;
                }
            }

            return true;
        });

        $this->images = array_merge(
            $psesImgs,
            array_map(static fn(array $img) => [...$img, 'isProductImg' => true], array_values($productImgs))
        );
    }

    private function getUniquePseImg(array $images, array $productImages): array
    {
        $positionByImageId = [];
        foreach ($productImages as $productImage) {
            $positionByImageId[$productImage['id']] = $productImage['position'] ?? 0;
        }

        $grouped = array_reduce($images, static function ($carry, $item) use ($positionByImageId) {
            $imageId = $item['productImageId'];
            $pseId = (string) $item['productSaleElementsId'];

            if (!isset($carry[$imageId])) {
                $carry[$imageId] = [
                    'pseIds' => [],
                    'id' => $imageId,
                    'position' => $positionByImageId[$imageId] ?? 0,
                ];
            }

            $carry[$imageId]['pseIds'][] = $pseId;

            return $carry;
        }, []);

        usort($grouped, static fn($a, $b) => $a['position'] <=> $b['position']);

        return $grouped;
    }

    public function isAvailableAttrValue($variant): bool
    {
        $combination = array_replace($this->currentCombination, $variant);
        $matchingPses = array_filter($this->getPses(), static function ($pse) use (&$combination) {
            return $pse['combination'] === $combination;
        });

        return \count($matchingPses) > 0;
    }
}
