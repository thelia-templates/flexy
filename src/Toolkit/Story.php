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

/**
 * A story a module hangs in the toolkit, next to the ones the theme finds under `components/`.
 *
 * The theme's own stories are located by walking its directory; a module's live wherever the
 * module keeps its templates, so the story names its template and its file: the first is what
 * the toolkit renders, the second is what "Show the code" reads.
 */
final readonly class Story
{
    /**
     * @param string      $category   the sidebar group the story is listed under, `Modules` for instance
     * @param string      $name       what the sidebar shows, `Flexy extension demo / Callout` for instance
     * @param string      $twigPath   the template the toolkit renders, as a `@Namespace/...` Twig path
     * @param string      $sourcePath the file of that template, shown by "Show the code"
     * @param string|null $status     one of the ComponentStatus constants, or null to claim nothing
     */
    public function __construct(
        public string $category,
        public string $name,
        public string $twigPath,
        public string $sourcePath,
        public ?string $status = null,
    ) {
        if ('' === trim($category) || '' === trim($name)) {
            throw new \InvalidArgumentException('A story needs a category and a name.');
        }

        if (!str_starts_with($twigPath, '@')) {
            throw new \InvalidArgumentException(\sprintf('The Twig path of story "%s" must be namespaced (`@Namespace/...`), "%s" given.', $name, $twigPath));
        }

        if (!is_readable($sourcePath)) {
            throw new \InvalidArgumentException(\sprintf('The source of story "%s" is not readable: "%s".', $name, $sourcePath));
        }

        if (null !== $status && !\in_array($status, [ComponentStatus::READY, ComponentStatus::WAITING, ComponentStatus::HIDDEN], true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown status "%s" for story "%s".', $status, $name));
        }
    }
}
