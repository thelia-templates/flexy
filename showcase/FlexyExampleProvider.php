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

namespace FlexyBundle\Showcase;

use OpenStudio\UxShowcaseBundle\Example\Example;
use OpenStudio\UxShowcaseBundle\Example\ExampleProviderInterface;
use OpenStudio\UxShowcaseBundle\Example\PreviewOptions;
use OpenStudio\UxShowcaseBundle\Library\ComponentStatus as ShowcaseStatus;
use FlexyBundle\Controller\ToolkitController;
use FlexyBundle\Template\FrontTemplateChain;
use FlexyBundle\Toolkit\ComponentStatus;
use FlexyBundle\Toolkit\ModuleStories;
use FlexyBundle\Toolkit\StoryFinder;

/**
 * Every story of the toolkit, as a free example: the whole `toolkit.html.twig` is the example, so the
 * showcase shows what the toolkit shows.
 *
 * A story of the theme is attached to the `<Path>:Base` component of its own directory when there is
 * one (`Molecules/Button` to `Molecules:Button:Base`), and is a documentation page of its category
 * otherwise. A story a module registers is a documentation page of the category it declares: it names
 * no component of the theme. Stories that are `hidden` are not read, the finder drops them.
 *
 * Not autoconfigured on purpose: the library hands it over, and a provider tagged as shared would feed
 * every library a second time.
 */
final readonly class FlexyExampleProvider implements ExampleProviderInterface
{
    /**
     * The stories that read records of the shop's catalogue or editorial content: a product, a
     * category, a sale, a content or a folder, by id or to build a menu. The check command skips them
     * unless it is given `--with-data`, so a CI whose database is empty still passes.
     *
     * Established on the demo shop by reading each story, then rendering all of them with Propel
     * logging on and listing the ones that query `product`, `category`, `sale`, `content`, `folder` or
     * the library images. Left out on purpose: the stories that only touch what an installation
     * seeds (customer titles, checkout steps, languages) or that fall back without data (the gallery
     * and the video player read an image id and use a placeholder outside a real product).
     *
     * Keyed by the slug of the story; a story is added here on purpose.
     *
     * @var list<string>
     */
    public const array NEEDS_DATA = [
        // A category, a product or a sale named by id ("categoryId=1", "productId=1", "sales/1").
        'layouts-crossselling',
        'layouts-productcategory',
        'layouts-saleshowcase',
        'organisms-categorycard',
        'organisms-productcard',
        // A content or a folder, by id or through the folder tree.
        'layouts-foldercontents',
        'layouts-similarcontent',
        'organisms-similarcontentcard',
        // The menus are built from the categories, folders and contents of the shop.
        'layouts-footer',
        'layouts-header',
        'organisms-headermenuitem',
        // Module story (TheliaCMS): every section type fed with products, categories and contents.
        'cms-all-sections',
    ];

    private const string THEME_PREFIX = '@Flexy/';

    private const string STORY_FILE = '/toolkit.html.twig';

    public function __construct(
        private StoryFinder $storyFinder,
        private ModuleStories $moduleStories,
        private FlexyComponents $components,
        private FrontTemplateChain $templateChain,
    ) {
    }

    /**
     * The groups of the pages that are not components (`Docs`, `Design tokens`), in the order of the
     * toolkit, for the ones that still have a page.
     *
     * @return list<string>
     */
    public function pageGroups(): array
    {
        $groups = [];

        foreach ($this->pages() as $page) {
            $groups[$page['group']] = true;
        }

        return array_map('strval', array_keys($groups));
    }

    /**
     * @return iterable<Example>
     */
    public function getExamples(): iterable
    {
        foreach ($this->pages() as $page) {
            yield new Example(
                ...self::statusArguments($page['status']),
                component: null,
                slug: $page['slug'],
                title: $page['name'],
                source: $page['source'],
                group: $page['group'],
            );
        }

        $moduleSlugs = [];

        foreach ($this->moduleStories->grouped() as $stories) {
            foreach ($stories as $story) {
                $moduleSlugs[$story['slug']] = true;
            }
        }

        // The page-side name (`Fields:Input:Base`) points at the name the catalog lists, which is
        // namespaced for an anonymous component the catalog does not resolve without its namespace.
        $components = [];

        foreach ($this->components->names() as $name) {
            $components[FlexyComponents::withoutNamespace($name)] = $name;
        }

        foreach ($this->storyFinder->groupedComponents() as $category => $stories) {
            foreach ($stories as $story) {
                $source = file_get_contents($story['path']);

                if (false === $source) {
                    throw new \RuntimeException(\sprintf('The story "%s" cannot be read: "%s".', $story['name'], $story['path']));
                }

                // The story slug keeps the case and the underscores of a directory name: a slug of the showcase does not.
                $slug = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $story['slug']) ?? ''), '-');
                $component = isset($moduleSlugs[$story['slug']]) ? null : $this->baseComponentOf($story['twigPath'], $components);

                // The package refuses a status on the example of a component: only a page of documentation has one.
                yield new Example(
                    ...(null === $component ? self::statusArguments($story['status']) : []),
                    component: $component,
                    slug: $slug,
                    title: $story['name'],
                    source: $source,
                    group: null === $component ? (string) $category : null,
                    preview: new PreviewOptions(needsData: \in_array($slug, self::NEEDS_DATA, true) ? true : null),
                );
            }
        }
    }

    /**
     * The status the toolkit shows beside a documentation page, as the named argument of `Example`.
     *
     * `status` exists from 0.3.1 of the package: an older one fails on the unknown named argument, so
     * it is passed only where the constructor takes it. Nothing when the toolkit gives the page no
     * status, or one the showcase does not show on a page (`hidden` is a page that is not listed).
     *
     * @param bool|null $exampleTakesAStatus whether the installed package knows the argument; read from `Example` when null
     *
     * @return array{status?: ShowcaseStatus}
     */
    public static function statusArguments(?string $status, ?bool $exampleTakesAStatus = null): array
    {
        if (null === $status || !($exampleTakesAStatus ?? self::exampleTakesAStatus())) {
            return [];
        }

        $showcaseStatus = ShowcaseStatus::tryFrom($status);

        return null === $showcaseStatus || ShowcaseStatus::Hidden === $showcaseStatus ? [] : ['status' => $showcaseStatus];
    }

    public static function exampleTakesAStatus(): bool
    {
        foreach ((new \ReflectionClass(Example::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ('status' === $parameter->getName()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The pages of the toolkit that are not components, as `buildPages()` of its controller keeps them:
     * the ones whose template is there and that are not `hidden`.
     *
     * @return list<array{slug: string, name: string, group: string, source: string, status: string|null}>
     */
    private function pages(): array
    {
        $pages = [];
        $breakpoints = null;

        foreach (ToolkitController::SECTIONS as $group => $sections) {
            foreach ($sections as $slug => $section) {
                $directory = $this->templateChain->nearest('components/Toolkit/'.$slug.'.html.twig', is_file(...));

                if (null === $directory || ComponentStatus::HIDDEN === $this->storyFinder->statusOf('Toolkit/'.$slug)) {
                    continue;
                }

                // The page of the breakpoints reads the ones the controller hands to the toolkit: they are
                // given to it here, as the example is rendered without any variable.
                $source = 'breakpoints' === $slug
                    ? $this->breakpointsSource($breakpoints ??= $this->breakpoints())
                    : (string) file_get_contents($directory.'/components/Toolkit/'.$slug.'.html.twig');

                $pages[] = ['slug' => $slug, 'name' => $section['name'], 'group' => (string) $group, 'source' => $source, 'status' => $this->storyFinder->statusOf('Toolkit/'.$slug)];
            }
        }

        return $pages;
    }

    /**
     * @return list<array{name: string, rem: float, px: float, label: string|null}>
     */
    private function breakpoints(): array
    {
        $directory = $this->templateChain->nearest('assets/styles/variables.css', is_file(...));

        return ShowcaseViewports::breakpointsFromCss(null === $directory ? '' : (string) file_get_contents($directory.'/assets/styles/variables.css'));
    }

    /**
     * @param list<array{name: string, rem: float, px: float, label: string|null}> $breakpoints
     */
    private function breakpointsSource(array $breakpoints): string
    {
        return "{% include '@Flexy/Toolkit/breakpoints.html.twig' with {breakpoints: ".json_encode($breakpoints, \JSON_THROW_ON_ERROR)."} %}";
    }

    /**
     * @param array<string, string> $components
     */
    private function baseComponentOf(string $twigPath, array $components): ?string
    {
        if (!str_starts_with($twigPath, self::THEME_PREFIX) || !str_ends_with($twigPath, self::STORY_FILE)) {
            return null;
        }

        $directory = substr($twigPath, \strlen(self::THEME_PREFIX), -\strlen(self::STORY_FILE));
        $base = str_replace('/', ':', $directory) . ':Base';

        return $components[$base] ?? null;
    }
}
