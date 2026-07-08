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
use Thelia\Model\CategoryQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\LangQuery;
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
 * Generic base implementation: it lists visible categories and products only.
 * Site-specific sections (curated CMS pages, editorial folders, …) are meant to
 * be added by the overriding theme.
 *
 * @phpstan-type SitemapUrl array{loc: string, lastmod: \DateTimeInterface|null, priority: string|null, changefreq: string|null}
 * @phpstan-type SitemapSettings array{changefreq: string, exclude_empty_category: bool, priority: array<string, mixed>}
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
     */
    public const SECTIONS = ['categories', 'products', 'images'];

    public function __construct(
        private readonly AdapterInterface $cache,
        private readonly CacheManager $cacheManager,
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
                default => ['sitemap-urlset', ['urls' => $this->getSectionUrls($section)]],
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
        $settings = $this->getModuleSettings();
        $excluded = $this->getNoindexObjectIds('category');

        $categories = CategoryQuery::create()
            ->filterByVisible(1)
            ->filterByParent(0)
            ->orderByPosition()
            ->find();

        $urls = [];
        foreach ($categories as $category) {
            $categoryId = $category->getId();

            if (\in_array($categoryId, $excluded, true)) {
                continue;
            }

            if (null !== $settings && $settings['exclude_empty_category'] && !$this->categoryHasVisibleProducts($categoryId)) {
                continue;
            }

            $urls[] = $this->buildUrl($category->getUrl($locale), $category->getUpdatedAt(), $settings, 'category', 'category', $categoryId);
        }

        return $urls;
    }

    /**
     * @return list<SitemapUrl>
     */
    private function getProductUrls(): array
    {
        $locale = $this->getDefaultLocale();
        $settings = $this->getModuleSettings();
        $excluded = $this->getNoindexObjectIds('product');

        $products = ProductQuery::create()
            ->filterByVisible(1)
            ->orderByPosition()
            ->find();

        $urls = [];
        foreach ($products as $product) {
            $productId = $product->getId();

            if (\in_array($productId, $excluded, true)) {
                continue;
            }

            $urls[] = $this->buildUrl($product->getUrl($locale), $product->getUpdatedAt(), $settings, 'product', 'product', $productId);
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
            ->endUse()
            ->find();

        $entries = [];
        foreach ($products as $product) {
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
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): array {
        return [
            'loc' => $loc,
            'lastmod' => $lastmod,
            'priority' => null === $settings ? null : $this->resolvePriority($settings['priority'][$priorityKey] ?? null, $sourceType, $sourceId),
            'changefreq' => $settings['changefreq'] ?? null,
        ];
    }

    /**
     * Per-element priority (set in the Sitemap module) overrides the default per-type priority.
     */
    private function resolvePriority(mixed $default, ?string $sourceType, ?int $sourceId): ?string
    {
        if (null !== $sourceType && null !== $sourceId) {
            try {
                $override = SitemapPriorityQuery::create()
                    ->filterBySource($sourceType)
                    ->filterBySourceId($sourceId)
                    ->findOne();

                if (null !== $override && null !== $override->getValue()) {
                    return $this->formatPriority($override->getValue());
                }
            } catch (\Throwable) {
                // sitemap_priority table unavailable: fall back to the default priority.
            }
        }

        return null === $default ? null : $this->formatPriority($default);
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
    private function getModuleSettings(): ?array
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
