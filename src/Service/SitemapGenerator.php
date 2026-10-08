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

namespace FlexyBundle\Service;

use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Propel\Runtime\ActiveQuery\Criteria;
use Psr\Cache\InvalidArgumentException;
use SEOne\Model\SeoneQuery;
use Sitemap\Model\SitemapPriorityQuery;
use Sitemap\Sitemap;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Thelia\Action\Image;
use Thelia\Core\Template\ParserInterface;
use Thelia\Domain\Sale\ReservedSaleVisibility;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\Map\ProductTableMap;
use Thelia\Model\ModuleQuery;
use Thelia\Model\ProductCategoryQuery;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductQuery;
use Thelia\Tools\URL;

/**
 * Native sitemap generator: a sitemap index (/sitemap.xml) pointing at per-section
 * secondary sitemaps. Product images are exposed as a Google image sitemap resized
 * through LiipImagine. The optional Sitemap and SEOne modules refine the output
 * (priority/changefreq, noindex exclusions) and degrade gracefully when absent.
 *
 * Several languages: when the Sitemap module is active and the shop shows more than one
 * language, every category and product is listed once per language, each entry carrying the
 * xhtml:link alternates of all of them and an x-default on the default language — the same set
 * of URLs, hreflang codes included, the SEOne module prints in the page head, so the sitemap and
 * the pages agree. Without the module, or with a single language, one entry per element in the
 * default language, as before.
 *
 * Generic base implementation: it lists visible categories and products only.
 * Site-specific sections (curated CMS pages, editorial folders, …) are meant to
 * be added by the overriding theme.
 *
 * @phpstan-type SitemapAlternate array{hreflang: string, href: string}
 * @phpstan-type SitemapUrl array{loc: string, lastmod: \DateTimeInterface|null, priority: string|null, changefreq: string|null, alternates: list<SitemapAlternate>}
 * @phpstan-type SitemapLang array{locale: string, hreflang: string, default: bool}
 * @phpstan-type SitemapSettings array{changefreq: string, exclude_empty_category: bool, priority: array<string, mixed>, overrides: array<int, string>}
 * @phpstan-type SitemapImageEntry array{loc: string, image_loc: string, image_title: string|null}
 */
final readonly class SitemapGenerator
{
    public const SITEMAP_CACHE_KEY = 'sitemap_';

    private const DEFAULT_TTL = 7200;

    /**
     * Liip filter used for product images in the Google image sitemap.
     * Its size/quality live in liip_imagine_thelia.yaml; the Sitemap module config,
     * when set, overrides them at runtime (see getImageRuntimeConfig()).
     */
    private const IMAGE_FILTER = 'sitemap';

    private const DEFAULT_IMAGE_TIMEOUT = 30;

    /**
     * Secondary sitemaps referenced by the sitemap index (/sitemap.xml).
     * "images" is a Google image sitemap (different XML schema), the others are plain urlsets.
     * Every urlset calls the `sitemap.urls` theme hook with its section as the context, so
     * a module adds its own addresses where they belong; "content" is the section whose
     * addresses all come from modules (the pages of a CMS, for one).
     */
    public const SECTIONS = ['categories', 'products', 'content', 'images'];

    public function __construct(
        private readonly AdapterInterface $cache,
        private readonly CacheManager $cacheManager,
        private readonly ReservedSaleVisibility $reservedSaleVisibility,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function generate(
        ?ParserInterface $parser,
        string $section,
        bool $flush,
    ): CacheItem {
        $cacheItem = $this->cache->getItem(self::SITEMAP_CACHE_KEY.$section);

        if ($flush || !$cacheItem->isHit()) {
            $cacheExpire = (int) ConfigQuery::read('sitemap_ttl', (string) self::DEFAULT_TTL) ?: self::DEFAULT_TTL;

            [$template, $variables] = match ($section) {
                'index' => ['sitemap-index', ['sitemaps' => $this->getIndexSitemaps()]],
                'images' => ['sitemap-images', ['entries' => $this->getImageEntries()]],
                default => ['sitemap-urlset', ['urls' => $this->getSectionUrls($section), 'section' => $section]],
            };

            $cacheItem->expiresAfter($cacheExpire);
            $cacheItem->set($parser?->render($template, $variables, false));
            $this->cache->save($cacheItem);
        }

        return $cacheItem;
    }

    /**
     * @return list<string> absolute URLs of the secondary sitemaps
     */
    private function getIndexSitemaps(): array
    {
        return array_map(
            static fn (string $section): string => URL::getInstance()->absoluteUrl('/sitemap-'.$section.'.xml'),
            self::SECTIONS,
        );
    }

    /**
     * @return list<SitemapUrl>
     */
    private function getSectionUrls(string $section): array
    {
        return match ($section) {
            'categories' => $this->getCategoryUrls(),
            'products' => $this->getProductUrls(),
            default => [],
        };
    }

    /**
     * Top-level (parent = 0) visible categories only.
     *
     * @return list<SitemapUrl>
     */
    private function getCategoryUrls(): array
    {
        $locale = $this->getDefaultLocale();
        $settings = $this->getModuleSettings('category');
        $excluded = $this->getNoindexObjectIds('category');

        $categories = CategoryQuery::create()
            ->filterByVisible(1)
            ->filterByParent(0)
            ->orderByPosition()
            ->find();

        $langs = $this->getAlternateLangs();
        $this->preloadUrls('category', $langs, array_map(static fn ($category): int => $category->getId(), iterator_to_array($categories)));

        $urls = [];
        foreach ($categories as $category) {
            $categoryId = $category->getId();

            if (\in_array($categoryId, $excluded, true)) {
                continue;
            }

            if (null !== $settings && $settings['exclude_empty_category'] && !$this->categoryHasVisibleProducts($categoryId)) {
                continue;
            }

            if ([] === $langs) {
                $urls[] = $this->buildUrl($category->getUrl($locale), $category->getUpdatedAt(), $settings, 'category', $categoryId);

                continue;
            }

            array_push($urls, ...$this->buildLocalizedUrls($category->getUrl(...), $langs, $category->getUpdatedAt(), $settings, 'category', $categoryId));
        }

        return $urls;
    }

    /**
     * @return list<SitemapUrl>
     */
    private function getProductUrls(): array
    {
        $locale = $this->getDefaultLocale();
        $settings = $this->getModuleSettings('product');
        $excluded = $this->getNoindexObjectIds('product');

        $products = ProductQuery::create()
            ->filterByVisible(1)
            ->orderByPosition();

        // Raw Propel query, unlike /api/front/products: the core's reserved-sale
        // filtering lives in the API extensions and the loops, not in ProductQuery
        // itself, so a hidden-drop's products must be excluded here explicitly —
        // a sitemap is crawled anonymously, and must show no more than an anonymous
        // visitor's own catalog does.
        $this->reservedSaleVisibility->applyTo($products, ProductTableMap::COL_ID);

        $products = $products->find();
        $langs = $this->getAlternateLangs();
        $this->preloadUrls('product', $langs, array_map(static fn ($product): int => $product->getId(), iterator_to_array($products)));

        $urls = [];
        foreach ($products as $product) {
            $productId = $product->getId();

            if (\in_array($productId, $excluded, true)) {
                continue;
            }

            if ([] === $langs) {
                $urls[] = $this->buildUrl($product->getUrl($locale), $product->getUpdatedAt(), $settings, 'product', $productId);

                continue;
            }

            array_push($urls, ...$this->buildLocalizedUrls($product->getUrl(...), $langs, $product->getUpdatedAt(), $settings, 'product', $productId));
        }

        return $urls;
    }

    /**
     * Google image sitemap: one representative (cover) image per visible product,
     * resized through LiipImagine (filter "sitemap"). When the Sitemap module is
     * active and configured, its image settings override the filter at runtime.
     * noindex products are excluded, like the product urlset.
     *
     * @return list<SitemapImageEntry>
     */
    private function getImageEntries(): array
    {
        $moduleActive = $this->isSitemapModuleActive();
        $timeout = $moduleActive ? (int) (Sitemap::getConfigValue('timeout') ?: self::DEFAULT_IMAGE_TIMEOUT) : self::DEFAULT_IMAGE_TIMEOUT;
        @ini_set('max_execution_time', (string) $timeout);

        $runtimeConfig = $moduleActive ? $this->getImageRuntimeConfig() : [];

        $locale = $this->getDefaultLocale();
        $excluded = $this->getNoindexObjectIds('product');

        $products = ProductQuery::create()
            ->filterByVisible(1)
            ->useProductCategoryQuery()
            ->orderByPosition()
            ->endUse();

        // Same rule as getProductUrls(): this is a raw Propel query, so the hidden
        // products of a reserved drop are excluded by hand.
        $this->reservedSaleVisibility->applyTo($products, ProductTableMap::COL_ID);

        $entries = [];
        foreach ($products->find() as $product) {
            $productId = $product->getId();

            if (\in_array($productId, $excluded, true)) {
                continue;
            }

            $image = ProductImageQuery::create()
                ->filterByProductId($productId)
                ->filterByVisible(1)
                ->orderByPosition()
                ->findOne();

            if (null === $image || '' === $image->getFile()) {
                continue;
            }

            $imageUrl = $this->generateImageUrl($image->getFile(), $runtimeConfig);

            if (null === $imageUrl) {
                continue;
            }

            $product->setLocale($locale);

            $entries[] = [
                'loc' => $product->getUrl($locale),
                'image_loc' => $imageUrl,
                'image_title' => $product->getTitle(),
            ];
        }

        return $entries;
    }

    /**
     * Runtime filter operations overriding the "sitemap" Liip filter, mapped from the
     * Sitemap module image config. LiipImagine wraps the runtime config under "filters",
     * so only filter *operations* can be overridden here — dimensions (downscale /
     * thumbnail + background depending on the resize mode), rotation and upscaling.
     * Encoder quality/format cannot ride the runtime path and stay on the static filter.
     * Returns an empty array when nothing is configured, leaving the filter untouched.
     *
     * @return array<string, mixed>
     */
    private function getImageRuntimeConfig(): array
    {
        $width = (int) (Sitemap::getConfigValue('width') ?: 0);
        $height = (int) (Sitemap::getConfigValue('height') ?: 0);
        $rotation = (int) (Sitemap::getConfigValue('rotation') ?: 0);
        $allowZoom = (bool) (Sitemap::getConfigValue('allow_zoom') ?: false);
        $backgroundColor = (string) (Sitemap::getConfigValue('background_color') ?: '');
        $resizeMode = (int) (Sitemap::getConfigValue('resize_mode') ?: Image::KEEP_IMAGE_RATIO);

        $operations = [];

        if ($width > 0 && $height > 0 && Image::EXACT_RATIO_WITH_CROP === $resizeMode) {
            // Exact box, crop the overflow.
            $operations['thumbnail'] = $this->thumbnailOperation($width, $height, 'outbound', $allowZoom);
        } elseif ($width > 0 && $height > 0 && Image::EXACT_RATIO_WITH_BORDERS === $resizeMode) {
            // Exact box, letterbox the image on a background.
            $operations['thumbnail'] = $this->thumbnailOperation($width, $height, 'inset', $allowZoom);
            $operations['background'] = ['size' => [$width, $height], 'position' => 'center', 'color' => '' !== $backgroundColor ? $backgroundColor : '#ffffff'];
        } elseif ($width > 0 || $height > 0) {
            // Keep ratio (or a single dimension): fit within the box, no crop/borders.
            $operations['downscale'] = ['max' => [$width ?: $height, $height ?: $width]];
        }

        if (0 !== $rotation) {
            $operations['rotate'] = ['angle' => $rotation];
        }

        return $operations;
    }

    /**
     * @return array<string, mixed>
     */
    private function thumbnailOperation(int $width, int $height, string $mode, bool $allowZoom): array
    {
        $operation = ['size' => [$width, $height], 'mode' => $mode];

        // allow_upscale is only added when true: a boolean false signs as "" but is sent
        // as "0" in the URL, which would fail LiipImagine's runtime signature check.
        if ($allowZoom) {
            $operation['allow_upscale'] = true;
        }

        return $operation;
    }

    /**
     * Resolves the public URL of a product image resized through LiipImagine.
     * Returns null if the filter runtime fails (e.g. missing source file).
     *
     * @param array<string, mixed> $runtimeConfig runtime overrides for the "sitemap" filter
     */
    private function generateImageUrl(string $file, array $runtimeConfig): ?string
    {
        try {
            return $this->cacheManager->getBrowserPath('/product/'.$file, self::IMAGE_FILTER, $runtimeConfig);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Builds a sitemap URL entry, adding <priority>/<changefreq> only when the
     * optional Sitemap module is installed and active (otherwise both stay null
     * and the template omits the tags).
     *
     * @param SitemapSettings|null $settings
     *
     * @return SitemapUrl
     */
    private function buildUrl(
        string $loc,
        ?\DateTimeInterface $lastmod,
        ?array $settings,
        string $priorityKey,
        ?int $sourceId = null,
    ): array {
        return [
            'loc' => $loc,
            'lastmod' => $lastmod,
            'priority' => null === $settings ? null : $this->resolvePriority($settings, $priorityKey, $sourceId),
            'changefreq' => $settings['changefreq'] ?? null,
            'alternates' => [],
        ];
    }

    /**
     * One entry per language of an element, each listing the alternates of all of them: a
     * search engine reads the language versions as a set only when every one names the others.
     * Two languages answering the same address (a locale with no URL of its own falling back
     * on another's) share one entry.
     *
     * @param callable(string): string $urlOf      the element's URL in a locale
     * @param non-empty-list<SitemapLang> $langs
     * @param SitemapSettings|null     $settings
     *
     * @return list<SitemapUrl>
     */
    private function buildLocalizedUrls(
        callable $urlOf,
        array $langs,
        ?\DateTimeInterface $lastmod,
        ?array $settings,
        string $priorityKey,
        int $sourceId,
    ): array {
        $alternates = [];
        $locs = [];

        foreach ($langs as $lang) {
            $href = $urlOf($lang['locale']);
            $locs[$href] = true;
            $alternates[] = ['hreflang' => $lang['hreflang'], 'href' => $href];

            if ($lang['default']) {
                $alternates[] = ['hreflang' => 'x-default', 'href' => $href];
            }
        }

        $base = $this->buildUrl('', $lastmod, $settings, $priorityKey, $sourceId);

        return array_map(
            static fn (string $loc): array => ['loc' => $loc, 'alternates' => $alternates] + $base,
            array_keys($locs),
        );
    }

    /**
     * The languages each element is listed in, or an empty list for a single listing in the
     * default language: without the Sitemap module, or when the shop shows one language only.
     *
     * The languages are the active and visible ones, like the hreflang links SEOne prints in the
     * page head, with the same codes — the language alone ("fr"), or the full locale ("fr-ca")
     * for the languages that share it, which the language alone would not tell apart.
     *
     * @return list<SitemapLang>
     */
    private function getAlternateLangs(): array
    {
        if (!$this->isSitemapModuleActive()) {
            return [];
        }

        $langs = LangQuery::create()
            ->filterByActive(1)
            ->filterByVisible(1)
            ->orderByPosition()
            ->find();

        if (\count($langs) < 2) {
            return [];
        }

        $languageOf = static fn (string $locale): string => strtolower(explode('_', $locale)[0]);
        $languageCount = array_count_values(array_map(static fn ($lang): string => $languageOf($lang->getLocale()), iterator_to_array($langs)));

        $alternateLangs = [];
        foreach ($langs as $lang) {
            $language = $languageOf($lang->getLocale());

            $alternateLangs[] = [
                'locale' => $lang->getLocale(),
                'hreflang' => $languageCount[$language] > 1 ? strtolower(str_replace('_', '-', $lang->getLocale())) : $language,
                'default' => (bool) $lang->getByDefault(),
            ];
        }

        return $alternateLangs;
    }

    /**
     * Reads the rewritten URLs of a whole section in one query per language, rather than one
     * per element and language.
     *
     * @param list<SitemapLang> $langs
     * @param list<int>         $ids
     */
    private function preloadUrls(string $view, array $langs, array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        $locales = [] === $langs ? [$this->getDefaultLocale()] : array_column($langs, 'locale');

        foreach ($locales as $locale) {
            URL::getInstance()->preloadRewrittenUrls($view, $locale, $ids);
        }
    }

    /**
     * Per-element priority (set in the Sitemap module) overrides the default per-type priority.
     *
     * @param SitemapSettings $settings
     */
    private function resolvePriority(array $settings, string $priorityKey, ?int $sourceId): ?string
    {
        if (null !== $sourceId && isset($settings['overrides'][$sourceId])) {
            return $settings['overrides'][$sourceId];
        }

        $default = $settings['priority'][$priorityKey] ?? null;

        return null === $default ? null : $this->formatPriority($default);
    }

    /**
     * The per-element priorities of a source type, read once per section rather than once
     * per address. When an element has several rows, the oldest wins, as findOne() did.
     *
     * @return array<int, string> formatted priority by element id
     */
    private function getPriorityOverrides(string $sourceType): array
    {
        try {
            $rows = SitemapPriorityQuery::create()
                ->filterBySource($sourceType)
                ->orderById()
                ->find();
        } catch (\Throwable) {
            // sitemap_priority table unavailable: fall back to the default priorities.
            return [];
        }

        $firstRows = [];
        foreach ($rows as $row) {
            $sourceId = $row->getSourceId();

            if (null !== $sourceId && !\array_key_exists((int) $sourceId, $firstRows)) {
                $firstRows[(int) $sourceId] = $row->getValue();
            }
        }

        // A first row without a value leaves the default priority, as it did when read alone.
        return array_map($this->formatPriority(...), array_filter($firstRows, static fn (mixed $value): bool => null !== $value));
    }

    private function formatPriority(mixed $value): string
    {
        return (string) (float) $value;
    }

    /**
     * Sitemap module parameters, or null when the module is absent or not activated
     * (in which case the sitemap keeps its default behaviour: no priority/changefreq,
     * no empty-category exclusion).
     *
     * @return SitemapSettings|null
     */
    private function getModuleSettings(string $sourceType): ?array
    {
        if (!$this->isSitemapModuleActive()) {
            return null;
        }

        return [
            'changefreq' => (string) Sitemap::getConfigValue('default_update_frequency', Sitemap::DEFAULT_FREQUENCY_UPDATE),
            'exclude_empty_category' => (bool) Sitemap::getConfigValue('exclude_empty_category', false),
            'priority' => [
                'category' => Sitemap::getConfigValue('default_priority_category_value', Sitemap::DEFAULT_PRIORITY_CATEGORY_VALUE),
                'product' => Sitemap::getConfigValue('default_priority_product_value', Sitemap::DEFAULT_PRIORITY_PRODUCT_VALUE),
            ],
            'overrides' => $this->getPriorityOverrides($sourceType),
        ];
    }

    /**
     * True when the optional Sitemap module is installed and activated.
     * Degrades silently to false if the module or its table is unavailable.
     */
    private function isSitemapModuleActive(): bool
    {
        if (!class_exists(Sitemap::class)) {
            return false;
        }

        try {
            return ModuleQuery::create()
                ->filterByCode('Sitemap')
                ->filterByActivate(1)
                ->count() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function categoryHasVisibleProducts(int $categoryId): bool
    {
        return ProductCategoryQuery::create()
            ->filterByCategoryId($categoryId)
            ->useProductQuery()
                ->filterByVisible(1)
            ->endUse()
            ->count() > 0;
    }

    /**
     * IDs of elements flagged "noindex" in the SEOne back-office, to exclude from the sitemap.
     *
     * Indexing is thus driven from each record's SEO tab: ticking "noindex" (which already
     * adds <meta robots="noindex"> on the page) also removes the element from the sitemap,
     * without touching its shop visibility. Degrades silently to an empty list when SEOne
     * is absent.
     *
     * @return list<int>
     */
    private function getNoindexObjectIds(string $objectType): array
    {
        try {
            $objectIds = SeoneQuery::create()
                ->filterByObjectType($objectType)
                ->useSeoneI18nQuery(null, Criteria::INNER_JOIN)
                    ->filterByLocale($this->getDefaultLocale())
                    ->filterByNoindex(1)
                ->endUse()
                ->select('ObjectId')
                ->find()
                ->toArray();

            return array_map('intval', $objectIds);
        } catch (\Throwable) {
            return [];
        }
    }

    private function getDefaultLocale(): string
    {
        return LangQuery::create()
            ->filterByByDefault(1)
            ->findOne()
            ?->getLocale() ?? 'fr_FR';
    }
}
