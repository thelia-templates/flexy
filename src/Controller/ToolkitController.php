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

use FlexyBundle\Template\FrontTemplateChain;
use FlexyBundle\Toolkit\ComponentStatus;
use FlexyBundle\Toolkit\StoryFinder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/toolkit', name: 'toolkit_')]
class ToolkitController extends AbstractController
{
    public function __construct(
        private readonly FrontTemplateChain $templateChain,
        private readonly StoryFinder $storyFinder,
        // By id: the service carries no alias for its class, and it only exists where the
        // profiler is installed.
        #[Autowire(service: 'profiler')]
        private readonly ?Profiler $profiler = null,
    ) {
    }

    /**
     * The pages that are not components, by sidebar group. Each is listed only while its file
     * is there, so deleting the file retires the page, navigation entry included.
     *
     * `standalone` renders the template whole and shows no status; without it the page is
     * framed like a component, which is what the token pages need.
     */
    public const array SECTIONS = [
        'Docs' => [
            'welcome' => ['name' => 'Welcome', 'standalone' => true],
            'getting-started' => ['name' => 'Getting started', 'standalone' => true],
        ],
        'Design tokens' => [
            'breakpoints' => ['name' => 'Breakpoints'],
            'layout' => ['name' => 'Layout'],
        ],
    ];

    /** What `/toolkit` opens on, named rather than inherited from the order of SECTIONS. */
    private const string HOME = 'welcome';

    public const array LABELS = [
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
     * Each component gets its own page, so the preview iframe renders one component rather
     * than the whole catalogue. `/toolkit` alone opens on HOME, or on the first page left.
     */
    #[Route('/{slug}', name: 'show', defaults: ['slug' => null])]
    public function show(Request $request, ?string $slug = null): Response
    {
        if (true !== $this->getParameter('kernel.debug')) {
            throw $this->createNotFoundException();
        }

        $grouped = $this->storyFinder->groupedComponents();
        $pages = $this->buildPages($grouped);

        $slug ??= isset($pages[self::HOME]) ? self::HOME : array_key_first($pages);

        if (null === $slug || !isset($pages[$slug])) {
            throw $this->createNotFoundException();
        }

        $page = $pages[$slug];
        $embed = $request->query->getBoolean('embed');

        // Embedded, the story is read by a script that measures its cells and audits the page:
        // the debug toolbar would be measured and audited with it.
        if ($embed) {
            $this->profiler?->disable();
        }

        $response = $this->render('@Flexy/Toolkit/show.html.twig', [
            'grouped' => $grouped,
            'sections' => $this->groupSections($pages),
            'breakpoints' => $this->getBreakpoints(),
            'embed' => $embed,
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
     * Flattens everything into one slug-indexed map, so every page resolves the same way. A
     * section page carries no `path`, so none of them offers its source.
     *
     * @param array<string, list<array{twigPath: string, path: string, name: string, slug: string, status: string|null}>> $grouped
     *
     * @return array<string, array{name: string, category: string|null, twigPath: string, status: string|null, group?: string, standalone?: bool, path?: string, slug?: string}>
     */
    private function buildPages(array $grouped): array
    {
        $pages = [];

        foreach (self::SECTIONS as $group => $slugs) {
            foreach ($slugs as $slug => $section) {
                if (null === $this->templateChain->nearest('components/Toolkit/' . $slug . '.html.twig', is_file(...))) {
                    continue;
                }

                $status = $this->storyFinder->statusOf('Toolkit/' . $slug);

                // `hidden` means the same here as for a component: gone from the toolkit.
                if (ComponentStatus::HIDDEN === $status) {
                    continue;
                }

                $pages[$slug] = [
                    'name' => $section['name'],
                    'category' => null,
                    'group' => $group,
                    'twigPath' => '@Flexy/Toolkit/' . $slug . '.html.twig',
                    'status' => $status,
                    'standalone' => $section['standalone'] ?? false,
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
     * Keeps the groups SECTIONS declares, so a heading disappears with its last page. `group`
     * is what marks a section page, not `standalone`, which a token page does not have.
     *
     * @param array<string, array{name: string, group?: string, status: string|null}> $pages
     *
     * @return array<string, array<string, array{name: string, status: string|null}>>
     */
    private function groupSections(array $pages): array
    {
        $sections = [];

        foreach ($pages as $slug => $page) {
            if (isset($page['group'])) {
                $sections[$page['group']][$slug] = [
                    'name' => $page['name'],
                    'status' => $page['status'],
                ];
            }
        }

        return $sections;
    }

    /**
     * @return list<array{name: string, rem: float, px: float, label: string|null}>
     */
    private function getBreakpoints(): array
    {
        // The nearest tokens file of the chain: a child template that redefines the breakpoints
        // ships its own variables.css, and the toolkit has to preview at its widths.
        $variablesDirectory = $this->templateChain->nearest('assets/styles/variables.css', is_file(...));
        $css = null === $variablesDirectory ? '' : file_get_contents($variablesDirectory . '/assets/styles/variables.css');

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
