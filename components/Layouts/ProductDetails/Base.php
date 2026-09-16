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
use FlexyBundle\Service\RunningSaleResolver;
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
use Thelia\Domain\Media\AltTextResolver;
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

    /**
     * The visuals of the product sheet, images and videos alike, in one list ordered by the
     * position the merchant gave them. Each entry carries only what the gallery renders:
     * type, id, the PSEs it illustrates, its alternative text, and for a video the platform,
     * the frame address, the hosted file address and the id of its poster image.
     *
     * @var list<array{type: string, id: int, pseIds: list<string>, alt: string, provider?: string|null, embedUrl?: string|null, fileUrl?: string|null, thumbnailImageId?: int|null}>
     */
    #[LiveProp]
    public array $media = [];

    #[LiveProp]
    public array $productAttrs = [];

    #[LiveProp]
    public array $currentCombination = [];

    #[LiveProp]
    public ?array $currentPse = null;

    #[LiveProp]
    public bool $noAvailablePse = false;

    /**
     * The rating of the product, as the front payload carries it under the `CommentRating`
     * addon key: the average of its accepted reviews and how many there are. LiveProps for the same reason as brandTitle above — the
     * component re-renders on every variant selection, in a process that never calls mount()
     * again. Two scalars, so the round-trip stays cheap.
     *
     * The average is null for a product with no review, never 0, and the heading then shows
     * no rating at all.
     */
    #[LiveProp]
    public ?float $ratingAverage = null;

    #[LiveProp]
    public int $ratingCount = 0;

    /**
     * The running-sale label/countdown for this product, or null when no active
     * operation asks to show one on it. A LiveProp, not a plain property computed once
     * in mount(): the component re-renders on every PSE selection, in a fresh process
     * that never calls mount() again, and a plain property would vanish from the
     * second render on — mirrors brandTitle/brandUrl above for the same reason.
     *
     * @var array{saleLabel: string, shouldDisplayCountdown: bool, countdownRemainingSeconds: int|null, publicUrl: string|null}|null
     */
    #[LiveProp]
    public ?array $runningSaleTag = null;

    private ?array $pses = null;

    public function __construct(
        private readonly DataAccessService $dataAccessService,
        private readonly ProductSaleElementsAccessService $pseAccessService,
        private readonly FormServiceInterface $formService,
        private readonly CartFacade $cartFacade,
        private readonly RequestStack $requestStack,
        private readonly RunningSaleResolver $runningSaleResolver,
        private readonly AltTextResolver $altTextResolver,
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
        $this->ratingAverage = isset($product['CommentRating']['ratingAverage']) ? (float) $product['CommentRating']['ratingAverage'] : null;
        $this->ratingCount = isset($product['CommentRating']['ratingCount']) ? (int) $product['CommentRating']['ratingCount'] : 0;
        // Keyed by attribute id upstream; re-indexed so the LiveProp round-trips as a list.
        $this->productAttrs = array_values($this->pseAccessService->attrAvByProduct($this->productId));
        $this->runningSaleTag = $this->runningSaleResolver->forProduct($this->productId);

        $this->setInitialCurrentPse();
        $this->setMedia();
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
     * The gallery shows images and videos side by side, each of them either tied to the PSEs it
     * illustrates or shared by every variant. Both kinds come from the API and are merged into a
     * single list ordered by position: the merchant arranges the sheet with one set of positions,
     * so a video put between two images has to come out between them.
     *
     * Four reads at most, and only two for a product with nothing on it: the join tables are
     * asked for only once there is something to join.
     */
    private function setMedia(): void
    {
        $productImages = $this->dataAccessService->resources(
            '/api/front/product_images',
            [
                'product.id' => $this->productId,
                'visible' => true,
                'order[position]' => 'asc',
            ]
        ) ?? [];

        $productVideos = $this->dataAccessService->resources(
            '/api/front/product_videos',
            [
                'product.id' => $this->productId,
                'visible' => true,
                'order[position]' => 'asc',
            ]
        ) ?? [];

        if ($productImages === [] && $productVideos === []) {
            $this->media = [];

            return;
        }

        $entries = array_merge(
            $this->imageEntries($productImages),
            $this->videoEntries($productVideos, $productImages),
        );

        // Stable on ties: two visuals sharing a position keep the order the API gave them,
        // images before videos, rather than swapping around from one render to the next.
        usort($entries, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        $this->media = array_map(
            static function (array $entry): array {
                unset($entry['position']);

                return $entry;
            },
            $entries
        );
    }

    /**
     * @param list<array<string, mixed>> $productImages
     *
     * @return list<array<string, mixed>>
     */
    private function imageEntries(array $productImages): array
    {
        if ($productImages === []) {
            return [];
        }

        $pseIdsByImageId = $this->pseIdsByMediaId(
            '/api/front/product_sale_elements_product_image',
            'productImageId',
            $productImages,
        );

        $entries = [];

        foreach ($productImages as $image) {
            $entries[] = [
                'type' => 'image',
                'id' => (int) $image['id'],
                'pseIds' => $pseIdsByImageId[$image['id']] ?? [],
                'alt' => $this->imageAltOf($image),
                'position' => (int) ($image['position'] ?? 0),
            ];
        }

        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $productVideos
     * @param list<array<string, mixed>> $productImages
     *
     * @return list<array<string, mixed>>
     */
    private function videoEntries(array $productVideos, array $productImages): array
    {
        if ($productVideos === []) {
            return [];
        }

        $pseIdsByVideoId = $this->pseIdsByMediaId(
            '/api/front/product_sale_elements_product_video',
            'productVideoId',
            $productVideos,
        );

        // A video without a poster of its own borrows the product's first image, so the gallery
        // shows the product rather than a grey box; with no image at all the template falls back
        // to the placeholder.
        $defaultThumbnailId = isset($productImages[0]['id']) ? (int) $productImages[0]['id'] : null;

        $entries = [];

        foreach ($productVideos as $video) {
            $embedUrl = $video['embedUrl'] ?? null;
            $fileUrl = $video['fileUrl'] ?? null;

            // Neither a frame to open nor a file to serve: the platform of this video was turned
            // off in the shop's settings after it was added, and the core hands back no address
            // for it. Dropped here rather than in the template, so it leaves no thumbnail in the
            // rail and no empty slide in the slider either.
            if (($embedUrl === null || $embedUrl === '') && ($fileUrl === null || $fileUrl === '')) {
                continue;
            }

            $thumbnailImageId = self::relationId($video['thumbnailImage'] ?? null);

            $entries[] = [
                'type' => 'video',
                'id' => (int) $video['id'],
                'pseIds' => $pseIdsByVideoId[$video['id']] ?? [],
                'alt' => $this->altOf($video),
                'provider' => $video['provider'] ?? null,
                'embedUrl' => $embedUrl,
                'fileUrl' => $fileUrl,
                'thumbnailImageId' => $thumbnailImageId ?? $defaultThumbnailId,
                'position' => (int) ($video['position'] ?? 0),
            ];
        }

        return $entries;
    }

    /**
     * One visual can illustrate several PSEs: collapse the join rows into the list of PSE ids
     * each visual carries, keyed by the visual's own id.
     *
     * @param list<array<string, mixed>> $media
     *
     * @return array<int|string, list<string>>
     */
    private function pseIdsByMediaId(string $path, string $mediaIdField, array $media): array
    {
        $rows = $this->dataAccessService->resources(
            $path,
            [
                $mediaIdField => array_map(static fn (array $item) => $item['id'], $media),
                'productSaleElements.product.id' => $this->productId,
            ]
        ) ?? [];

        $pseIds = [];

        foreach ($rows as $row) {
            $pseIds[$row[$mediaIdField]][] = (string) $row['productSaleElementsId'];
        }

        return $pseIds;
    }

    /**
     * The alternative text the page will render, decided here and never in the template: the rule
     * belongs to the core, and the gallery has no business re-deciding it per tag. `i18ns` reaches
     * the component already resolved to the current language by the API data layer.
     *
     * @param array<string, mixed> $resource
     */
    private function altOf(array $resource): string
    {
        $i18ns = \is_array($resource['i18ns'] ?? null) ? $resource['i18ns'] : [];

        return $this->altTextResolver->resolve(
            \is_string($i18ns['alt'] ?? null) ? $i18ns['alt'] : null,
            (bool) ($resource['decorative'] ?? false),
            \is_string($i18ns['title'] ?? null) ? $i18ns['title'] : null,
        );
    }

    /**
     * An empty alt says "this image carries no information, skip it", which is true of an image
     * the merchant marked decorative and of nothing else. An image nobody got round to naming
     * is still a picture of the product, so it is announced with the product's own name — the
     * same fallback the product cards use. The core resolver stays as it is: it decides on the
     * image alone, and has no idea what product it hangs on.
     *
     * @param array<string, mixed> $image
     */
    private function imageAltOf(array $image): string
    {
        $alt = $this->altOf($image);

        if ('' !== $alt || (bool) ($image['decorative'] ?? false)) {
            return $alt;
        }

        return (string) $this->title;
    }

    /**
     * A relation comes back either embedded or as an IRI, depending on the group it was read in.
     */
    private static function relationId(mixed $relation): ?int
    {
        if (\is_array($relation)) {
            return isset($relation['id']) ? (int) $relation['id'] : null;
        }

        if (\is_string($relation) && preg_match('#/(\d+)$#', $relation, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
