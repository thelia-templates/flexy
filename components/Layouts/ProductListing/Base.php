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

namespace FlexyBundle\Components\Layouts\ProductListing;

use FlexyBundle\DTO\ProductDTO;
use FlexyBundle\Form\Type\FieldsetType;
use FlexyBundle\Service\FormService;
use FlexyBundle\Service\ProductImageResolver;
use FlexyBundle\Service\ProductSearch;
use FlexyBundle\Service\ProductTaxationResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
use Symfony\UX\TwigComponent\Attribute\PostMount;
use Thelia\Api\Service\DataAccess\DataAccessService;

#[AsLiveComponent]
class Base extends AbstractController
{
    use ComponentToolsTrait;
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    public const ITEMS_PER_PAGE = 30;

    /**
     * Matches the source project's own sort list exactly (`CategoryFilters::SORTS` in
     * backport/wpc) — not the 5 options shown in the Figma mockup, which are illustrative.
     */
    public const SORTS = [
        ['value' => 'asc', 'title' => 'Ascending price'],
        ['value' => 'desc', 'title' => 'Descending price'],
    ];

    #[LiveProp]
    public ?int $categoryId = null;

    /**
     * Set by the brand page. Narrows the listing to one brand, and nothing else: Thelia defines
     * its product filters per category, so a brand listing has no facet to offer (see
     * getFilters()) — only the sort and the pagination.
     */
    #[LiveProp]
    public ?int $brandId = null;

    #[LiveProp]
    public int $page = 1;

    #[LiveProp]
    public bool $promo = false;

    #[LiveProp]
    public bool $newness = false;

    /**
     * Set by the search page. Not url-bound: the term belongs to the page's own query string
     * (`?query=`), which pagination links already carry over — see paginationBaseUrl().
     */
    #[LiveProp]
    public ?string $searchTerm = null;

    #[LiveProp(writable: true, url: true)]
    public array $tfilters = [];

    #[LiveProp(writable: true, url: true)]
    public ?string $sort = null;

    #[ExposeInTemplate]
    public array $products = [];

    #[ExposeInTemplate]
    public array $pagination = [];

    #[ExposeInTemplate]
    public array $filters = [];

    #[ExposeInTemplate]
    public int $activeFilterCount = 0;

    /**
     * Filter definitions already read during this request, by selection: the form building and
     * the listing refresh both ask for them.
     *
     * @var array<string, array>
     */
    private array $resolvedFilters = [];

    public function __construct(
        private readonly DataAccessService $dataAccessService,
        private readonly RequestStack $requestStack,
        private readonly FormService $formService,
        private readonly ProductImageResolver $productImageResolver,
        private readonly ProductTaxationResolver $productTaxationResolver,
        private readonly ProductSearch $productSearch,
    ) {
    }

    public function mount(
        ?int $categoryId = null,
        ?int $brandId = null,
        bool $promo = false,
        bool $newness = false,
        ?string $searchTerm = null,
    ): void {
        $this->categoryId = $categoryId;
        $this->brandId = $brandId;
        $this->promo = $promo;
        $this->newness = $newness;
        $this->searchTerm = $searchTerm;

        // Pagination links reload the page, so the page number is read back from the query
        // string. `tfilters`/`sort` don't need the same treatment here: they're url-bound
        // LiveProps (see #[PostMount] below for why that matters).
        $this->page = max(1, (int) ($this->requestStack->getCurrentRequest()?->query->get('page') ?? 1));
    }

    /**
     * `tfilters`/`sort` are url-bound LiveProps (`url: true`): on a fresh page load, the
     * framework only copies their value from the query string into the property AFTER
     * mount() returns (Symfony\UX\LiveComponent\EventListener\RequestInitializeSubscriber,
     * triggered by PostMountEvent) — building the initial listing inside mount() itself would
     * always see their un-hydrated default (`[]`/`null`), silently ignoring the URL. A
     * #[PostMount] hook runs after that hydration, so this is the earliest point their real
     * value is available. LiveAction calls (save()) are unaffected: those hydrate the full
     * component from the request before invoking the action, mount()/#[PostMount] don't run.
     */
    #[PostMount]
    public function postMount(): void
    {
        $this->refreshListing();
    }

    #[LiveAction]
    public function save(#[LiveArg] bool $reset = false, #[LiveArg] ?string $sort = null): void
    {
        $this->submitForm();

        if ($reset) {
            $this->tfilters = [];
            $this->sort = null;
            $this->resetForm();
        } else {
            $this->tfilters = $this->getForm()->getData() ?? [];
            // `sort` can only be cleared through the $reset branch above: the placeholder option of
            // Fields:Select:Base is rendered disabled, so it can never be re-selected to send null.
            $this->sort = $sort ?? $this->sort;
            // The facets follow the selection, so the form that was just submitted describes the
            // column before this action: it is rebuilt from the new selection for the re-render.
            $this->resetForm();
        }

        // A narrower result set can have fewer pages than the one currently displayed
        $this->page = 1;

        $this->refreshListing();
    }

    /**
     * Filter definitions come from the product's category: Thelia exposes none without one,
     * so a category-less listing (view_all) has no filters at all.
     *
     * The current selection goes along, so each value is offered with the number of products
     * it would keep and the values no product of the narrowed set holds disappear. Thelia
     * relaxes a checked filter from its own facet, so a checked value always keeps its siblings.
     */
    public function getFilters(): array
    {
        if ($this->categoryId === null) {
            return $this->filters = [];
        }

        $selection = $this->categoryTFilter() + $this->tfilters;
        $key = json_encode($selection);

        if (($this->resolvedFilters[$key] ?? null) === null) {
            $this->resolvedFilters[$key] = $this->dataAccessService->resources(
                '/api/front/tfilters/products',
                ['tfilters' => $selection],
            ) ?? [];
        }

        return $this->filters = $this->resolvedFilters[$key];
    }

    /**
     * #[ExposeInTemplate] so `sorts` is a plain top-level template variable, not just
     * `this.sorts`: inside a nested `<twig:Molecules:Accordion:...>` block, `this` is rebound
     * to that inner component's own instance, which has no such property — `this.sorts` would
     * silently resolve to nothing there and the mobile sort list would render empty.
     */
    #[ExposeInTemplate]
    public function getSorts(): array
    {
        return self::SORTS;
    }

    protected function instantiateForm(): FormInterface
    {
        $formBuilder = $this->createFormBuilder(null, ['attr' => ['class' => 'ProductListing-form']]);
        $filters = $this->getFilters();

        if ($filters === []) {
            return $formBuilder->getForm();
        }

        $formBuilder->add($formBuilder->create(
            'tfilters',
            FieldsetType::class,
            [
                'label' => 'Filter by',
                'inherit_data' => true,
                'label_attr' => ['class' => 'ProductListing-legend'],
            ]
        ));

        foreach ($filters as $filter) {
            $this->formService->renderFieldFromFieldType($filter, $formBuilder->get('tfilters'), $this->tfilters);
        }

        return $formBuilder->getForm();
    }

    /**
     * Both the product list and the filter definitions are re-read on mount and after every
     * LiveAction, so a re-render never shows a stale sidebar.
     */
    private function refreshListing(): void
    {
        $this->getFilters();
        $this->tfilters = $this->normalizeTfilters($this->tfilters);
        $this->activeFilterCount = $this->countSelectedValues($this->tfilters);

        $totalItems = $this->fetchProducts();
        $lastPage = max(1, (int) ceil($totalItems / self::ITEMS_PER_PAGE));

        // Out of range the API serves the last page anyway; realigning keeps the pager from
        // offering a "next" that leads nowhere.
        if ($this->page > $lastPage) {
            $this->page = $lastPage;
            $totalItems = $this->fetchProducts();
        }

        // One call for the whole grid instead of one per card.
        $productIds = array_map(static fn (ProductDTO $product): int => $product->id, $this->products);
        $this->productImageResolver->preload($productIds);
        $this->productTaxationResolver->preload($productIds);
        $this->pagination = [
            'totalItems' => $totalItems,
            'itemsPerPage' => self::ITEMS_PER_PAGE,
            'currentPage' => $this->page,
            'baseUrl' => $this->paginationBaseUrl(),
        ];
    }

    /**
     * A search listing goes through the theme's search service rather than building its own
     * product query: that is the seam a Thelia search module would replace.
     */
    private function fetchProducts(): int
    {
        if ($this->isSearch()) {
            $result = $this->productSearch->search($this->searchTerm ?? '', $this->page, self::ITEMS_PER_PAGE, $this->sort);
            $this->products = $result['products'];

            return (int) $result['total'];
        }

        $response = $this->dataAccessService->resources('/api/front/products', $this->productParameters(), 'jsonld');
        $this->products = ProductDTO::fromCollection($response['hydra:member'] ?? []);

        return (int) ($response['hydra:totalItems'] ?? 0);
    }

    /**
     * Any non-null term means the consumer is a search listing — including the empty string, which
     * the search page sends when it was opened without a query. Treating that as "not a search"
     * would fall through to the plain catalogue and list every product under a heading claiming
     * zero matches.
     */
    private function isSearch(): bool
    {
        return $this->searchTerm !== null;
    }

    private function productParameters(): array
    {
        $parameters = [
            'visible' => true,
            'itemsPerPage' => self::ITEMS_PER_PAGE,
            'page' => $this->page,
        ];

        if ($this->categoryId !== null) {
            $parameters['productCategories.category.id'] = $this->categoryId;
        }

        if ($this->brandId !== null) {
            $parameters['brand.id'] = $this->brandId;
        }

        if ($this->promo) {
            $parameters['productSaleElements.promo'] = true;
        }

        if ($this->newness) {
            $parameters['productSaleElements.newness'] = true;
        }

        if ($this->tfilters !== []) {
            $parameters['tfilters'] = $this->tfilters;
        }

        if ($this->sort !== null) {
            $parameters['untaxed_price_order'] = $this->sort;
        } else {
            $positionProperty = $this->categoryId !== null ? 'productCategories.position' : 'position';
            $parameters['order['.$positionProperty.']'] = 'asc';
        }

        // Deterministic tiebreaker: paginating without a total order lets a product repeat on one
        // page and vanish from another. Products often share a position, and prices tie too.
        $parameters['order[ref]'] = 'asc';

        return $parameters;
    }

    /**
     * A range/delta slider is always submitted with a value, even untouched — its default is the
     * filter's own bounds. Sent to the API as-is it still filters, silently excluding products that
     * lack the attribute (e.g. a mere sort change would drop the listing). So an unnarrowed
     * range/delta is dropped entirely here: not sent to the API, not counted, not kept in the URL.
     * Kept values are also clamped to the filter's bounds and ordered (min ≤ max), since a forged
     * URL can send out-of-range or inverted values (min > max) that would break the slider.
     */
    private function normalizeTfilters(array $tfilters): array
    {
        foreach ($tfilters as $type => $group) {
            if (!\is_array($group)) {
                continue;
            }

            foreach ($group as $fieldName => $values) {
                $filter = $this->findFilter((string) $type, (string) $fieldName);

                if ($filter === null || !\in_array($filter['fieldType'] ?? null, ['range', 'delta'], true)) {
                    continue;
                }

                $filterValues = \is_array($filter['values'] ?? null) ? $filter['values'] : [];
                $sanitized = $this->sanitizeRange($filterValues, $values);

                if ($sanitized === null) {
                    unset($tfilters[$type][$fieldName]);
                } else {
                    $tfilters[$type][$fieldName] = $sanitized;
                }
            }

            if (($tfilters[$type] ?? null) === []) {
                unset($tfilters[$type]);
            }
        }

        return $tfilters;
    }

    /**
     * Clamps a range/delta value to the filter's [min, max] bounds and orders it (a forged URL can
     * send values outside the bounds, or min > max). Returns null when the result no longer narrows
     * the interval — an inert filter that must be dropped.
     *
     * @param array<mixed> $filterValues
     *
     * @return array{min: float, max: float}|string|null
     */
    private function sanitizeRange(array $filterValues, mixed $values): array|string|null
    {
        $bounds = array_column($filterValues, 'title');

        if ($bounds === []) {
            return null;
        }

        $min = (float) min($bounds);
        $max = (float) max($bounds);
        $clamp = static fn (float $value): float => max($min, min($max, $value));

        if (\is_array($values)) {
            $a = $clamp((float) ($values['min'] ?? $min));
            $b = $clamp((float) ($values['max'] ?? $max));
            $low = min($a, $b);
            $high = max($a, $b);

            return $low <= $min && $high >= $max ? null : ['min' => $low, 'max' => $high];
        }

        if (!$this->isSelectedValue($values)) {
            return null;
        }

        $value = $clamp((float) $values);

        return $value > $min && $value < $max ? (string) $value : null;
    }

    /**
     * Counts the active filter selections for the "Clear (n)" affordance. tfilters is shaped
     * [filterType => [filterId => values]] (a text search sits unwrapped at the top level).
     *
     * A range/delta slider always submits a value once the form has been posted — even untouched,
     * where it rests on the filter's own bounds — so it must only be counted when it actually
     * narrows the [min, max] interval. Otherwise a mere sort change (which re-submits the form)
     * would light up the badge with filters the user never set.
     */
    private function countSelectedValues(array $tfilters): int
    {
        $count = 0;

        foreach ($tfilters as $type => $group) {
            if (!\is_array($group)) {
                $count += $this->isSelectedValue($group) ? 1 : 0;
                continue;
            }

            foreach ($group as $fieldName => $values) {
                $count += $this->countFieldSelection((string) $type, (string) $fieldName, $values);
            }
        }

        return $count;
    }

    private function countFieldSelection(string $type, string $fieldName, mixed $values): int
    {
        $filter = $this->findFilter($type, $fieldName);

        if ($filter !== null && \in_array($filter['fieldType'] ?? null, ['range', 'delta'], true)) {
            $filterValues = \is_array($filter['values'] ?? null) ? $filter['values'] : [];

            return $this->isRangeNarrowed($filterValues, $values) ? 1 : 0;
        }

        if (\is_array($values)) {
            return \count(array_filter($values, $this->isSelectedValue(...)));
        }

        return $this->isSelectedValue($values) ? 1 : 0;
    }

    /**
     * Whether a range/delta value narrows the filter's full [min, max] interval. A delta carries
     * both bounds (['min' => …, 'max' => …]); a single range carries one value, which can only be
     * treated as active when it sits strictly inside the bounds — its untouched default (the
     * slider's mid-point) is not reconstructable server-side, so that case stays best-effort.
     *
     * @param array<mixed> $filterValues
     */
    private function isRangeNarrowed(array $filterValues, mixed $values): bool
    {
        $bounds = array_column($filterValues, 'title');

        if ($bounds === []) {
            return false;
        }

        $min = (float) min($bounds);
        $max = (float) max($bounds);

        if (\is_array($values)) {
            return (float) ($values['min'] ?? $min) > $min || (float) ($values['max'] ?? $max) < $max;
        }

        return $this->isSelectedValue($values) && (float) $values > $min && (float) $values < $max;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findFilter(string $type, string $fieldName): ?array
    {
        foreach ($this->filters as $filter) {
            if (!\is_array($filter)) {
                continue;
            }

            if (($filter['type'] ?? null) === $type
                && (string) ($filter['id'] ?? $filter['type'] ?? '') === $fieldName) {
                return $filter;
            }
        }

        return null;
    }

    /**
     * A blank text search or an unchecked box leaves an empty value behind: it must not inflate
     * the "Clear (n)" badge.
     */
    private function isSelectedValue(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    /**
     * CategoryFilter walks two levels below `tfilters[category]`, and single-element levels
     * are unwrapped again when the id is read back — see FilterService.
     */
    private function categoryTFilter(): array
    {
        return ['category' => [[$this->categoryId]]];
    }

    /**
     * Pagination links trigger a full page load, so the active filters and sort have to survive in
     * the query string. A LiveAction runs as a separate POST to the component endpoint, so its own
     * query string is not the page's — the browser URL travels in the X-Live-Url header. Parse it
     * for the page-identity params (view/category_id/lang/type…), then override the url-bound
     * filters/sort with the component's own authoritative state (the header still carries their
     * pre-action values). On the initial full-page render there is no header: use the query string.
     */
    private function paginationBaseUrl(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        if (($liveUrl = $request?->headers->get('X-Live-Url')) !== null) {
            $query = [];
            parse_str((string) parse_url($liveUrl, \PHP_URL_QUERY), $query);
        } else {
            $query = $request?->query->all() ?? [];
        }

        unset($query['page'], $query['tfilters'], $query['sort']);

        if ($this->tfilters !== []) {
            $query['tfilters'] = $this->tfilters;
        }

        if ($this->sort !== null) {
            $query['sort'] = $this->sort;
        }

        return $query === [] ? '' : '?'.http_build_query($query);
    }
}
