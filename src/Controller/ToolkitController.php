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

namespace FlexyBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Finder\Finder;
use FlexyBundle\Toolkit\ComponentStatus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/toolkit', name: 'toolkit_')]
class ToolkitController extends AbstractController
{
    /**
     * The pages that are not components, by sidebar group. Each is listed only while its
     * file is there: deleting the file is all it takes to retire a page from a project that
     * no longer needs it. Docs come first, so `/toolkit` lands on the welcome page.
     */
    private const array SECTIONS = [
        'Docs' => [
            'welcome' => 'Welcome',
            'getting-started' => 'Getting started',
        ],
        'Design tokens' => [
            'breakpoints' => 'Breakpoints',
            'layout' => 'Layout',
        ],
    ];

    private const array LABELS = [
        '2xs' => 'Mobile S',
        'xs' => 'Mobile M',
        'sm' => 'Mobile L',
        'md' => 'Tablet',
        'lg' => 'Small Desktop',
        'xl' => 'Desktop',
        '2xl' => 'Large Desktop',
    ];

    /**
     * The toolkit renders every component of the theme and walks the theme directory
     * to find them. That is a developer tool: on a live shop it exposes the internals
     * of the template, and search engines index a page no customer should reach. It
     * answers only where the kernel runs in debug, and says nothing about itself
     * anywhere else - a 404, not a 403, so its existence is not advertised either.
     *
     * Each component gets its own page (so the preview iframe only ever has to
     * render one component instead of the whole catalogue), sharing the same
     * sidebar navigation. The slug is optional so `/toolkit` alone lands on a
     * sensible default page.
     */
    #[Route('/{slug}', name: 'show', defaults: ['slug' => null])]
    public function show(Request $request, ?string $slug = null): Response
    {
        if (true !== $this->getParameter('kernel.debug')) {
            throw $this->createNotFoundException();
        }

        $grouped = $this->getGroupedComponents();
        $pages = $this->buildPages($grouped);

        $slug ??= array_key_first($pages);

        if (!isset($pages[$slug])) {
            throw $this->createNotFoundException();
        }

        $page = $pages[$slug];

        $response = $this->render('@Flexy/Toolkit/show.html.twig', [
            'grouped' => $grouped,
            'sections' => $this->groupSections($pages),
            'breakpoints' => $this->getBreakpoints(),
            'embed' => $request->query->getBoolean('embed'),
            'currentSlug' => $slug,
            'page' => $page,
            'source' => isset($page['path']) ? file_get_contents($page['path']) : null,
        ]);

        // Second lock, for the day the page is deliberately opened on a demo running
        // in debug: the header travels even where the markup is not read.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * @return array<string, list<array{twigPath: string, path: string, name: string, slug: string, status: string|null}>>
     */
    private function getGroupedComponents(): array
    {
        $finder = (new Finder())
            ->files()
            ->name('toolkit.html.twig')
            ->in(\dirname(__DIR__, 2) . '/components')
            ->sortByName();

        $grouped = [];

        foreach ($finder as $file) {
            $status = ComponentStatus::of($file->getRelativePath());

            if (ComponentStatus::HIDDEN === $status) {
                continue;
            }

            $parts = explode('/', $file->getRelativePath());
            $category = $parts[0];
            $name = \count($parts) > 1 ? implode(' / ', \array_slice($parts, 1)) : $category;
            $slug = strtolower(implode('-', $parts));

            $grouped[$category][] = [
                'twigPath' => '@Flexy/' . $file->getRelativePathname(),
                'path' => $file->getRealPath(),
                'name' => $name,
                'slug' => $slug,
                'status' => $status,
            ];
        }

        foreach (['Forms', 'Layouts'] as $category) {
            if (isset($grouped[$category])) {
                $items = $grouped[$category];
                unset($grouped[$category]);
                $grouped[$category] = $items;
            }
        }

        return $grouped;
    }

    /**
     * Flattens the grouped components into a slug-indexed map and prepends the pages that
     * are not components, so every page resolves the same way. The first entry of the first
     * group comes first, which is what makes it the page `/toolkit` lands on.
     *
     * A page marked `standalone` renders its own template whole, rather than being framed
     * as a component story. Those carry neither `path` nor `slug`: nothing is read from disk.
     *
     * @param array<string, list<array{twigPath: string, path: string, name: string, slug: string, status: string|null}>> $grouped
     *
     * @return array<string, array{name: string, category: string|null, twigPath: string, status: string|null, group?: string, standalone?: bool, path?: string, slug?: string}>
     */
    private function buildPages(array $grouped): array
    {
        $pages = [];

        foreach (self::SECTIONS as $group => $slugs) {
            foreach ($slugs as $slug => $name) {
                if (!is_file(\dirname(__DIR__, 2) . '/components/Toolkit/' . $slug . '.html.twig')) {
                    continue;
                }

                $status = ComponentStatus::of('Toolkit/' . $slug);

                // `hidden` means the same thing here as it does for a component: gone from the
                // toolkit. Without this the page stayed routable, listed, and rendered a status
                // no stylesheet knows about.
                if (ComponentStatus::HIDDEN === $status) {
                    continue;
                }

                $pages[$slug] = [
                    'name' => $name,
                    'category' => null,
                    'group' => $group,
                    'twigPath' => '@Flexy/Toolkit/' . $slug . '.html.twig',
                    'status' => $status,
                    'standalone' => true,
                ];
            }
        }

        foreach ($grouped as $category => $components) {
            foreach ($components as $component) {
                $pages[$component['slug']] = $component + ['category' => $category];
            }
        }

        return $pages;
    }

    /**
     * Keeps the sidebar groups the SECTIONS constant declares, so a heading disappears with
     * its last page rather than standing empty.
     *
     * A standalone page always carries its group: buildPages() sets both together.
     *
     * @param array<string, array{name: string, group?: string, standalone?: bool}> $pages
     *
     * @return array<string, array<string, array{name: string, group: string, standalone: bool}>>
     */
    private function groupSections(array $pages): array
    {
        $sections = [];

        foreach ($pages as $slug => $page) {
            if ($page['standalone'] ?? false) {
                $sections[$page['group']][$slug] = $page;
            }
        }

        return $sections;
    }

    private function getBreakpoints(): array
    {
        $variablesPath = \dirname(__DIR__, 2) . '/assets/styles/variables.css';
        $css = is_file($variablesPath) ? file_get_contents($variablesPath) : '';

        preg_match_all('/--breakpoint-([\w-]+):\s*([\d.]+)rem/', (string) $css, $matches, \PREG_SET_ORDER);

        $breakpoints = [];
        foreach ($matches as $match) {
            $breakpoints[$match[1]] = (float) $match[2];
        }

        asort($breakpoints);

        return array_map(
            static fn (string $name, float $rem): array => [
                'name' => $name,
                'rem' => $rem,
                'px' => $rem * 16,
                'label' => self::LABELS[$name] ?? null,
            ],
            array_keys($breakpoints),
            array_values($breakpoints),
        );
    }
}
