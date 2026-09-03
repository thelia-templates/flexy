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

namespace FlexyBundle\Components\Layouts\ProductDetails;

use FlexyBundle\Event\CheckoutEvents;
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
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Api\Service\DataAccess\ProductSaleElementsAccessService;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Cart\DTO\CartItemAddDTO;
use Thelia\Form\Definition\FrontForm;
use Thelia\Model\ConfigQuery;

#[AsLiveComponent]
class Base
{
    use ComponentToolsTrait;
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    /**
     * Only what the component itself needs is kept, not the whole API resource mounted by the page:
     * every LiveProp is serialized into the DOM and posted back on each action, and the resource
     * weighs about 3.4 kB of fields consumed by the page template instead.
     */
    #[LiveProp]
    public int $productId = 0;

    #[LiveProp]
    public bool $virtual = false;

    #[LiveProp]
    public ?string $chapo = null;

    /**
     * Passed in by the page rather than read from SEOne here: the SEO helpers and attr() resolve
     * against the Thelia view context, which does not exist on the LiveComponent endpoint — read
     * from inside the component the heading would empty out after the first live action.
     */
    #[LiveProp]
    public ?string $title = null;

    /**
     * The brand of the product, flattened to the two strings the heading renders. Kept as
     * LiveProps rather than mounted once: the component re-renders on every action, and a
     * plain mount argument would be gone from the second render on. The front product
     * resource carries the brand's position only, so the page reads the brand itself and
     * passes it in — already filtered on visibility.
     */
    #[LiveProp]
    public ?string $brandTitle = null;

    #[LiveProp]
    public ?string $brandUrl = null;

    #[LiveProp]
    public array $images = [];

    #[LiveProp]
    public array $productAttrs = [];

    #[LiveProp]
    public array $currentCombination = [];

    #[LiveProp]
    public ?array $currentPse = null;

    #[LiveProp]
    public bool $noAvailablePse = false;

    private ?array $pses = null;

    public function __construct(
        private readonly DataAccessService $dataAccessService,
        private readonly ProductSaleElementsAccessService $pseAccessService,
        private readonly FormServiceInterface $formService,
        private readonly CartFacade $cartFacade,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function mount(array $product, ?string $title = null, ?array $brand = null): void
    {
        $this->productId = (int) $product['id'];
        $this->virtual = (bool) ($product['virtual'] ?? false);
        $this->chapo = $product['i18ns']['chapo'] ?? null;
        $this->title = $title ?: ($product['i18ns']['title'] ?? null);
        $this->brandTitle = $brand['i18ns']['title'] ?? null;
        $this->brandUrl = $brand['publicUrl'] ?? null;
        // Keyed by attribute id upstream; re-indexed so the LiveProp round-trips as a list.
        $this->productAttrs = array_values($this->pseAccessService->attrAvByProduct($this->productId));

        $this->setInitialCurrentPse();
        $this->setImages();
    }

    /**
     * Each attribute value carries whether picking it leads to an existing PSE, so an impossible
     * combination can be shown disabled instead of letting the shopper hit a dead end. Computed at
     * render time, not on mount: availability depends on the rest of the current combination.
     */
    #[ExposeInTemplate('productAttributes')]
    public function getProductAttributes(): array
    {
        $attributes = $this->productAttrs;

        foreach ($attributes as $index => $attribute) {
            foreach ($attribute['values'] ?? [] as $valueIndex => $value) {
                $attributes[$index]['values'][$valueIndex]['available'] = $this->isAvailableAttrValue(
                    [(int) $attribute['id'] => $value['id']]
                );
            }
        }

        return $attributes;
    }

    /**
     * Stock is only enforced front-side when the `check-available-stock` config is enabled
     * and the product is not virtual (mirrors Thelia core CartAdd / OrderFacade logic).
     */
    public function isStockManaged(): bool
    {
        if ($this->virtual) {
            return false;
        }

        return ConfigQuery::checkAvailableStock();
    }

    public function getRemainingStock(): int
    {
        if (!$this->isStockManaged()) {
            return \PHP_INT_MAX;
        }

        return max(0, (int) ($this->currentPse['quantity'] ?? 0) - $this->getCartQuantityForCurrentPse());
    }

    public function getPromoRate(): float
    {
        $price = (float) ($this->currentPse['untaxedPrice'] ?? 0);
        $promoPrice = (float) ($this->currentPse['promoUntaxedPrice'] ?? 0);

        if ($price <= 0.0) {
            return 0.0;
        }

        // Negative, so the gallery tag reads as a discount once formatted as a percentage.
        return -(($price - $promoPrice) / $price);
    }

    public function isMaxQuantityReached(): bool
    {
        if (!$this->isStockManaged()) {
            return false;
        }

        $stock = (int) ($this->currentPse['quantity'] ?? 0);

        return $stock > 0 && $this->getRemainingStock() <= 0;
    }

    #[LiveAction]
    public function updateCurrentPseFromId(#[LiveArg] ?string $pseIds = null): void
    {
        if ($pseIds === null || $pseIds === '') {
            return;
        }

        $ids = explode(',', $pseIds);
        $match = null;

        foreach ($this->getPses() as $pse) {
            if (\in_array((string) $pse['id'], $ids, true)) {
                $match = $pse;
                break;
            }
        }

        $this->selectPse($match);
    }

    #[LiveAction]
    public function updateCurrentCombination(#[LiveArg] int|string $attr, #[LiveArg] int|string $value): void
    {
        $combination = $this->currentCombination;
        $combination[(int) $attr] = (int) $value;
        $this->currentCombination = $combination;

        $match = null;

        foreach ($this->getPses() as $pse) {
            if ($this->matchesCombination($pse['combination'] ?? [], $combination)) {
                $match = $pse;
                break;
            }
        }

        $this->selectPse($match);

        if ($match !== null) {
            $this->dispatchBrowserEvent('change:pse', ['pseId' => $match['id']]);
        }
    }

    #[LiveAction]
    public function save(): void
    {
        // Clamp the requested quantity to the remaining stock before validation, so a manually
        // typed value above the stock cannot trigger a 422. getRemainingStock() returns
        // PHP_INT_MAX when stock is not managed.
        $remainingStock = $this->getRemainingStock();

        if ($remainingStock > 0 && (int) ($this->formValues['quantity'] ?? 1) > $remainingStock) {
            $this->formValues['quantity'] = $remainingStock;
        }

        $this->submitForm();
        $formData = $this->getForm()->getData();

        $this->cartFacade->addItem(
            new CartItemAddDTO(
                cart: $this->cartFacade->getOrCreateFromSession(),
                productId: (int) $formData['product'],
                productSaleElementId: (int) $formData['product_sale_elements_id'],
                quantity: (int) $formData['quantity'],
                append: (bool) $formData['append'],
                newness: (bool) $formData['newness'],
            )
        );

        $this->emit('addToCart', ['values' => $this->formValues]);
        $this->emit(CheckoutEvents::ADD_ITEM_EVENT);
    }

    protected function instantiateForm(): FormInterface
    {
        return $this->formService->getFormByName(FrontForm::CART_ADD, [
            'product' => $this->productId,
            'product_sale_elements_id' => $this->currentPse['id'] ?? null,
            'quantity' => 1,
            'append' => 1,
            'newness' => 0,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getPses(): array
    {
        return $this->pses ??= json_decode(
            $this->pseAccessService->psesByProduct($this->productId) ?: '[]',
            true,
        ) ?? [];
    }

    private function selectPse(?array $pse): void
    {
        $this->noAvailablePse = $pse === null;

        if ($pse === null) {
            return;
        }

        $this->currentPse = $pse;
        $this->currentCombination = $pse['combination'] ?? [];
        $this->formValues['product_sale_elements_id'] = $pse['id'];
    }

    private function isAvailableAttrValue(array $variant): bool
    {
        $combination = array_replace($this->currentCombination, $variant);

        foreach ($this->getPses() as $pse) {
            if ($this->matchesCombination($pse['combination'] ?? [], $combination)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Loose array comparison on purpose: both sides hold the same attributeId => attributeAvId
     * pairs but their key order follows each PSE's own attribute_combination rows, and a strict
     * comparison would report every combination as unavailable the day the two orders diverge.
     */
    private function matchesCombination(array $pseCombination, array $combination): bool
    {
        return $pseCombination == $combination;
    }

    private function getCartQuantityForCurrentPse(): int
    {
        if ($this->currentPse === null) {
            return 0;
        }

        $cart = $this->cartFacade->getCartFromSession();

        if ($cart === null) {
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

    /**
     * A `?ref` in the URL points at a precise PSE (that is how the gallery and the product listing
     * deep-link a variant); without it the product's default PSE is selected.
     */
    private function setInitialCurrentPse(): void
    {
        $pses = $this->getPses();
        $pseRef = $this->requestStack->getCurrentRequest()?->query->get('ref');
        $match = null;

        if ($pseRef !== null && $pseRef !== '') {
            $match = $this->findPse($pses, static fn (array $pse): bool => $pse['ref'] === $pseRef);
        }

        $match ??= $this->findPse($pses, static fn (array $pse): bool => (bool) ($pse['isDefault'] ?? false));
        $match ??= $pses[0] ?? null;

        if ($match === null) {
            return;
        }

        $this->currentPse = $match;
        $this->currentCombination = $match['combination'] ?? [];
    }

    /**
     * @param array<int, array<string, mixed>> $pses
     */
    private function findPse(array $pses, callable $predicate): ?array
    {
        foreach ($pses as $pse) {
            if ($predicate($pse)) {
                return $pse;
            }
        }

        return null;
    }

    /**
     * The gallery shows one visual per PSE that has its own, followed by the product-level visuals
     * shared by every variant. Both lists come from the API and are merged in position order.
     */
    private function setImages(): void
    {
        $productImages = $this->dataAccessService->resources(
            '/api/front/product_images',
            [
                'product.id' => $this->productId,
                'visible' => true,
            ]
        ) ?? [];

        if ($productImages === []) {
            $this->images = [];

            return;
        }

        $pseImages = $this->dataAccessService->resources(
            '/api/front/product_sale_elements_product_image',
            [
                'productImageId' => array_map(static fn (array $image) => $image['id'], $productImages),
                'productSaleElements.product.id' => $this->productId,
                'visible' => true,
            ]
        ) ?? [];

        $sharedImages = array_filter(
            $productImages,
            static function (array $image) use ($pseImages): bool {
                foreach ($pseImages as $pseImage) {
                    if ($pseImage['productImageId'] === $image['id']) {
                        return false;
                    }
                }

                return true;
            }
        );

        // Only the three fields the gallery reads are kept: the API resource also carries the
        // product IRI, both timestamps and an i18ns list, all of them dead weight in a LiveProp.
        $this->images = array_merge(
            $this->groupImagesByPse($pseImages, $productImages),
            array_map(
                static fn (array $image) => ['id' => $image['id'], 'isProductImg' => true],
                array_values($sharedImages)
            )
        );
    }

    /**
     * One visual can illustrate several PSEs: collapse the join rows into a single entry carrying
     * every PSE id, ordered by the product image's own position.
     */
    private function groupImagesByPse(array $pseImages, array $productImages): array
    {
        $positionByImageId = [];

        foreach ($productImages as $productImage) {
            $positionByImageId[$productImage['id']] = $productImage['position'] ?? 0;
        }

        $grouped = [];

        foreach ($pseImages as $pseImage) {
            $imageId = $pseImage['productImageId'];

            $grouped[$imageId] ??= [
                'id' => $imageId,
                'pseIds' => [],
                'position' => $positionByImageId[$imageId] ?? 0,
            ];

            $grouped[$imageId]['pseIds'][] = (string) $pseImage['productSaleElementsId'];
        }

        $grouped = array_values($grouped);
        usort($grouped, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return $grouped;
    }
}
