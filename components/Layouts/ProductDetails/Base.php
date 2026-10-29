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
use FlexyBundle\Service\BundleProductReader;
use FlexyBundle\Service\BundleSelectionService;
use FlexyBundle\Service\ProductImageResolver;
use FlexyBundle\Service\RunningSaleResolver;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Api\Service\DataAccess\ProductSaleElementsAccessService;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Cart\DTO\CartItemAddDTO;
use Thelia\Domain\Cart\Exception\InvalidCartException;
use Thelia\Domain\Cart\Exception\NotEnoughStockException;
use Thelia\Domain\Catalog\Bundle\BundleDisplayedStock;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Media\AltTextResolver;
use Thelia\Domain\Shipping\DestinationRestriction\CartDestinations;
use Thelia\Domain\Shipping\DestinationRestriction\DestinationRestrictionChecker;
use Thelia\Domain\Shipping\DestinationRestriction\ProductDestinationRestrictionReader;
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

    /**
     * Set when the cart refused the last add: a module rule (an InvalidCartException thrown by a
     * CART_ADDITEM listener) or the stock that ran out since the page was rendered. A plain
     * property, not a LiveProp: it belongs to the response of the action that failed, and the next
     * action renders without it.
     */
    public bool $cartRefused = false;

    /**
     * The reason a module gave with its refusal, shown after the theme's sentence and printed
     * escaped, as on the cart. Never the core's stock message, which names the product reference
     * and is not translated.
     */
    public string $cartRefusalReason = '';

    /**
     * The product is a bundle: a sellable product made of the sale elements of other products. Every
     * other product keeps the sheet it always had, none of the bundle code below is reached.
     */
    #[LiveProp]
    public bool $isBundle = false;

    /**
     * The components of the bundle, scalars only (see BundleProductReader). A LiveProp, signed and
     * posted back with every action, for the reason the others are: the component re-renders in a
     * process that never calls mount() again.
     *
     * @var list<array{itemId: int, productId: int, title: string, required: bool, quantity: string, min: string|null, max: string|null}>
     */
    #[LiveProp]
    public array $bundleRows = [];

    /**
     * The quantity of the components the shopper can change, by bundle item id. One key per quantity
     * field, set when the sheet is mounted, at the default quantity: the live controller of the browser
     * writes a model that exists in the state it was rendered with and throws on any other, so a key
     * added only when the shopper first touches the field would never be reached (the cart does the same
     * with items[idx].quantity). The sheet is still the same for every visitor, and cacheable. A quantity
     * equal to the default one changes nothing: only the others are a composition of the shopper's.
     * Writable, so the quantity fields bind to it: the core validates whatever comes back, on every
     * price shown and again on the add to the cart.
     *
     * @var array<int|string, string>
     */
    #[LiveProp(writable: true)]
    public array $bundleSelection = [];

    private ?array $pses = null;

    private ?array $bundleView = null;

    private ?array $composed = null;

    private ?string $composedFor = null;

    /**
     * The shop refused the choice of the shopper in the request being answered: told once, by this render.
     */
    private bool $selectionRefused = false;

    public function __construct(
        private readonly DataAccessService $dataAccessService,
        private readonly ProductSaleElementsAccessService $pseAccessService,
        private readonly FormServiceInterface $formService,
        private readonly CartFacade $cartFacade,
        private readonly RequestStack $requestStack,
        private readonly RunningSaleResolver $runningSaleResolver,
        private readonly AltTextResolver $altTextResolver,
        // Optional: the destination restrictions exist only in a core that carries them, and a unit test builds this component by hand.
        private readonly ?ProductDestinationRestrictionReader $destinationRestrictionReader = null,
        private readonly ?DestinationRestrictionChecker $destinationRestrictionChecker = null,
        private readonly ?LangService $langService = null,
        // Optional for the same reason: a core without the bundles has neither, and a bundle is then not met.
        private readonly ?BundleProductReader $bundleReader = null,
        private readonly ?BundleSelectionService $bundleSelectionService = null,
        private readonly ?ProductImageResolver $productImageResolver = null,
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
        // Read defensively: a core that predates the bundles publishes no such field.
        $this->isBundle = (bool) ($product['isBundle'] ?? false) && null !== $this->bundleReader && null !== $this->bundleSelectionService;
        $this->bundleRows = $this->isBundle ? $this->bundleReader->read($this->productId) : [];
        $this->bundleSelection = $this->defaultBundleSelection();

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
     * What the sheet says about where the product ships: its active destination groups, by
     * country name, and whether the address the visitor is known to have is refused by one of
     * them. The address is the delivery address of the cart, else the default address of the
     * customer, and never the country of the shop: a visitor nobody knows gets the lists alone.
     * Data only, the template words and translates it. Computed at render time, as the cart
     * and the address book change without the product page being mounted again.
     *
     * @return array{groups: list<array{onlyServed: bool, reason: string, destinations: list<string>}>, refusesAddress: bool}
     */
    #[ExposeInTemplate('destinationRestrictions')]
    public function getDestinationRestrictions(): array
    {
        $none = ['groups' => [], 'refusesAddress' => false];

        if (
            !class_exists(ProductDestinationRestrictionReader::class)
            || !class_exists(DestinationRestrictionChecker::class)
            || null === $this->destinationRestrictionReader
            || null === $this->destinationRestrictionChecker
            || !$this->destinationRestrictionChecker->hasActiveGroup()
        ) {
            return $none;
        }

        $locale = $this->langService?->getLocale() ?? 'en_US';
        $groups = $this->destinationRestrictionReader->forProduct($this->productId, $locale);

        if ([] === $groups) {
            return $none;
        }

        $cart = $this->cartFacade->getCartFromSession();
        $delivery = null === $cart ? null : CartDestinations::delivery($cart, true);
        $billing = null === $cart ? null : CartDestinations::billing($cart, true);
        // A download is licensed to the billing address and a parcel goes to the delivery one: the checker picks.
        $refusesAddress = [] !== $this->destinationRestrictionChecker->violationsForProducts([$this->productId], $delivery, $billing, $locale);

        return [
            'groups' => array_map(
                static fn (array $group): array => [
                    'onlyServed' => $group['onlyServed'],
                    'reason' => $group['reason'],
                    'destinations' => $group['destinations'],
                ],
                $groups,
            ),
            'refusesAddress' => $refusesAddress,
        ];
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

        // A bundle of virtual components only is supplied without limit: the core says so with the
        // largest quantity it publishes, which is not a stock to show nor to cap the quantity with.
        if ($this->isBundle && (int) ($this->currentPse['quantity'] ?? 0) >= BundleDisplayedStock::DISPLAY_LIMIT) {
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

    /**
     * What the sheet of a bundle shows: its components with what the shopper may do with each, the
     * price of the composition as it stands, and the two ways the sheet can be unable to offer it.
     * Null for a product that is not a bundle.
     *
     * Computed at render time: the composition is the shopper's, and the price is the core's answer
     * for it. One answer per render, never one per component. The visuals of the components are
     * preloaded as a group.
     *
     * @return array{components: list<array<string, mixed>>, pricing: array{price: float, originalPrice: float|null, saving: float|null}|null, unavailable: bool, refused: bool}|null
     */
    #[ExposeInTemplate('bundle')]
    public function getBundle(): ?array
    {
        if (!$this->isBundle) {
            return null;
        }

        return $this->bundleView ??= $this->buildBundleView();
    }

    /**
     * A bundle priced by its components shows the price of the composition: its promo tag says a
     * percentage of a catalog price that is nowhere, and the sheet says what is saved instead.
     */
    public function isComputedBundle(): bool
    {
        return null !== ($this->getBundle()['pricing'] ?? null);
    }

    /**
     * A choice the shop refused is shown as the default composition: the fields go back to their default
     * quantity, so that the next change starts from what is drawn and the refusal is not told again.
     */
    #[PreReRender]
    public function forgetARefusedSelection(): void
    {
        if (!$this->isBundle || !$this->composeBundle()['fellBack']) {
            return;
        }

        $this->bundleSelection = $this->defaultBundleSelection();
        $this->selectionRefused = true;
        $this->bundleView = null;
    }

    /**
     * One key per quantity field, at the default quantity (see $bundleSelection).
     *
     * @return array<int, string>
     */
    private function defaultBundleSelection(): array
    {
        if (!$this->isBundle) {
            return [];
        }

        $defaults = $this->bundleSelectionService->defaultQuantities($this->productId, $this->bundleRows);
        $selection = [];

        foreach ($this->bundleRows as $row) {
            if ('quantity' === $this->bundleControl($row)) {
                $selection[$row['itemId']] = $defaults[$row['itemId']];
            }
        }

        return $selection;
    }

    /**
     * What the shopper can do with a component: nothing (listed), switch it, or pick a quantity.
     *
     * @param array{required: bool, min: string|null, max: string|null, quantity: string} $row
     */
    private function bundleControl(array $row): string
    {
        $lowest = $row['min'] ?? $row['quantity'];
        $highest = $row['max'] ?? $row['quantity'];

        return $row['required'] && $lowest === $highest ? 'none' : ($this->isSwitch($row) ? 'switch' : 'quantity');
    }

    /**
     * Puts an optional component in the bundle, or takes it out. The shopper's quantity for a component
     * that is either there or not: the quantity of a variable one is bound to the field instead.
     */
    #[LiveAction]
    public function toggleBundleItem(#[LiveArg] int $item): void
    {
        $row = $this->bundleRow($item);

        if (null === $row || $row['required'] || !$this->isSwitch($row)) {
            return;
        }

        $current = $this->composeBundle()['composition']?->quantityOf($item) ?? '0';
        $this->bundleSelection[$item] = '0' === $current ? $row['quantity'] : '0';
        $this->bundleView = null;
    }

    /**
     * @return array{composition: \Thelia\Domain\Catalog\Bundle\BundleComposition|null, fellBack: bool, refusal: string|null}
     */
    private function composeBundle(): array
    {
        // Asked several times by one render (the refusal check, the view, the actions): the core
        // is read once per selection, and a changed selection is a new key.
        $key = json_encode([$this->productId, $this->bundleSelection], \JSON_THROW_ON_ERROR);

        if ($this->composedFor !== $key || null === $this->composed) {
            $this->composed = $this->bundleSelectionService->compose($this->productId, $this->bundleRows, $this->bundleSelection);
            $this->composedFor = $key;
        }

        return $this->composed;
    }

    /**
     * A component is a switch when it is either in the bundle or not: the lowest and the highest
     * quantity the merchant allows are the same.
     *
     * @param array{min: string|null, max: string|null, quantity: string} $row
     */
    private function isSwitch(array $row): bool
    {
        return ($row['min'] ?? $row['quantity']) === ($row['max'] ?? $row['quantity']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function bundleRow(int $itemId): ?array
    {
        foreach ($this->bundleRows as $row) {
            if ($row['itemId'] === $itemId) {
                return $row;
            }
        }

        return null;
    }

    private function buildBundleView(): array
    {
        $composed = $this->composeBundle();
        $composition = $composed['composition'];

        $this->productImageResolver?->preload(array_column($this->bundleRows, 'productId'));

        $components = [];

        foreach ($this->bundleRows as $row) {
            $lowest = $row['min'] ?? $row['quantity'];
            $highest = $row['max'] ?? $row['quantity'];
            $current = $composition?->quantityOf($row['itemId']) ?? $row['quantity'];

            $components[] = [
                'itemId' => $row['itemId'],
                'title' => $row['title'],
                'imageId' => $this->productImageResolver?->firstImageId($row['productId']),
                'required' => $row['required'],
                'quantity' => $current,
                'control' => $this->bundleControl($row),
                'included' => '0' !== $current,
                // A component that is not required may be left out, so its field starts at zero.
                'minQuantity' => $row['required'] ? $lowest : '0',
                'maxQuantity' => $highest,
            ];
        }

        $pricing = null;

        if (null !== $composition && null !== $this->currentPse) {
            $pricing = $this->bundleSelectionService->quote($this->productId, (int) $this->currentPse['id'], $composition);
        }

        return [
            'components' => $components,
            'pricing' => $pricing,
            'unavailable' => null === $composition,
            'refused' => $composed['fellBack'] || $this->selectionRefused,
        ];
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

        $quantitiesBefore = $this->quantitiesInCart();

        // A refusal of the cart is the shopper's business, not a server error: without this the
        // live request answered 500 and the selector stayed as it was, with no word of why.
        try {
            // The composition the shopper sees is the one added, and the core judges it again at the cart.
            // A choice the sheet had to put aside is not added in its place: the shopper is told it was refused.
            $bundleArguments = [];

            if ($this->isBundle) {
                $composed = $this->composeBundle();

                if (null === $composed['composition'] || $composed['fellBack']) {
                    // No reason: the core's sentence is a developer one, the page says the add was refused.
                    throw new InvalidCartException('');
                }

                $bundleArguments = ['bundleComposition' => $composed['composition']];
            }

            $cartItem = $this->cartFacade->addItem(
                new CartItemAddDTO(...[
                    'cart' => $this->cartFacade->getOrCreateFromSession(),
                    'productId' => (int) $formData['product'],
                    'productSaleElementId' => (int) $formData['product_sale_elements_id'],
                    'quantity' => (int) $formData['quantity'],
                    'append' => (bool) $formData['append'],
                    'newness' => (bool) $formData['newness'],
                    ...$bundleArguments,
                ])
            );
        } catch (InvalidCartException|NotEnoughStockException $exception) {
            $this->cartRefused = true;
            $this->cartRefusalReason = $exception instanceof InvalidCartException ? trim($exception->getMessage()) : '';

            return;
        }

        $this->emit('addToCart', ['values' => $this->formValues]);
        $this->emit(CheckoutEvents::ADD_ITEM_EVENT);

        // What the line gained, not what the form asked for: with `append` off the form's quantity replaces the one the line had.
        $difference = (int) $cartItem->getQuantity() - ($quantitiesBefore[(int) $cartItem->getId()] ?? 0);

        if (0 !== $difference) {
            $this->dispatchBrowserEvent(
                $difference > 0 ? CheckoutEvents::BROWSER_ADD_PSE : CheckoutEvents::BROWSER_REMOVE_PSE,
                ['pse' => (int) $formData['product_sale_elements_id'], 'quantity' => abs($difference)],
            );
        }
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

    /**
     * The quantity of each line of the session's cart, by line id (none when there is no cart yet).
     *
     * @return array<int, int>
     */
    private function quantitiesInCart(): array
    {
        $quantities = [];

        foreach ($this->cartFacade->getCartFromSession()?->getCartItems() ?? [] as $cartItem) {
            $quantities[(int) $cartItem->getId()] = (int) $cartItem->getQuantity();
        }

        return $quantities;
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
