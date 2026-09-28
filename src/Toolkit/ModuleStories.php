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

namespace FlexyBundle\Toolkit;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The stories the active modules hang in the toolkit, shaped like the ones the theme finds
 * under `components/`, so the controller lists both without telling them apart.
 */
final readonly class ModuleStories
{
    /**
     * @param iterable<StoryProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('flexy.toolkit_story_provider')]
        private iterable $providers,
    ) {
    }

    /**
     * @return array<string, list<array{twigPath: string, path: string, name: string, slug: string, status: string|null}>>
     */
    public function grouped(): array
    {
        $grouped = [];
        $slugs = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->stories() as $story) {
                if (ComponentStatus::HIDDEN === $story->status) {
                    continue;
                }

                $slug = self::slug($story);

                // Two stories on one slug would leave one of them unreachable, silently.
                if (isset($slugs[$slug])) {
                    throw new \LogicException(\sprintf('Stories "%s" and "%s" share the toolkit slug "%s".', $slugs[$slug], $story->name, $slug));
                }

                $slugs[$slug] = $story->name;

                $grouped[$story->category][] = [
                    'twigPath' => $story->twigPath,
                    'path' => $story->sourcePath,
                    'name' => $story->name,
                    'slug' => $slug,
                    'status' => $story->status,
                ];
            }
        }

        return $grouped;
    }

    /**
     * Same shape as the theme's slugs (`molecules-button`): the category and the name,
     * lowercased, with every run of anything else collapsed to one dash.
     */
    private static function slug(Story $story): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $story->category . ' ' . $story->name) ?? '');

        return trim($slug, '-');
    }
}
