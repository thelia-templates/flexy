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

use FlexyBundle\FlexyBundle;
use FlexyBundle\Template\FrontTemplateChain;
use OpenStudio\UxShowcaseBundle\Library\ComponentCatalog;
use Symfony\Component\Finder\Finder;

/**
 * The Twig components of the theme, named the way the showcase lists them: `Molecules:Button:Base`.
 *
 * The catalog knows every component of the application. The ones of the theme are those under a
 * top-level directory of a `components/` of the template chain, the toolkit's own pages and the
 * `toolkit.html.twig` stories excepted. An anonymous component (a template with no PHP class) is
 * listed by the catalog under the Twig namespace of its template, `theme_flexy:Fields:Input:Base`;
 * the name a page writes, `Fields:Input:Base`, is used wherever the catalog knows it.
 */
class FlexyComponents
{
    /** The directory of the toolkit's own pages: not a component category. */
    private const string TOOLKIT_DIRECTORY = 'Toolkit';

    private const string STORY_NAME = 'toolkit';

    /** @var list<string>|null */
    private ?array $names = null;

    public function __construct(
        private readonly ComponentCatalog $catalog,
        private readonly FrontTemplateChain $templateChain,
    ) {
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        if (null !== $this->names) {
            return $this->names;
        }

        return $this->names = self::select(
            $this->catalog->names(),
            $this->categories(),
            array_map(
                static fn (string $directory): string => FlexyBundle::templateNamespace($directory) . ':',
                $this->templateChain->directories(),
            ),
            $this->catalog->exists(...),
        );
    }

    /**
     * What names() does once the catalog, the categories and the namespaces of the chain are read.
     *
     * @param list<string>              $catalogNames every component name the catalog lists
     * @param list<string>              $categories   the top-level directories of the theme's components
     * @param list<string>              $namespaces   `theme_flexy:` for each template of the chain
     * @param callable(string): bool    $isKnown      whether the catalog knows a component by that name
     *
     * @return list<string>
     */
    public static function select(array $catalogNames, array $categories, array $namespaces, callable $isKnown): array
    {
        $names = [];

        foreach ($catalogNames as $raw) {
            $name = $raw;

            foreach ($namespaces as $namespace) {
                if (str_starts_with($raw, $namespace)) {
                    $name = substr($raw, \strlen($namespace));

                    break;
                }
            }

            $segments = explode(':', $name);

            if (!\in_array($segments[0], $categories, true) || self::STORY_NAME === end($segments)) {
                continue;
            }

            $names[$name] = $name !== $raw && !$isKnown($name) ? $raw : $name;
        }

        ksort($names, \SORT_STRING);

        return array_values($names);
    }

    /**
     * `Molecules:Button:Base` is the `Molecules/Button` directory: where statuses and stories are keyed.
     */
    public static function directoryOf(string $name): string
    {
        $segments = explode(':', self::withoutNamespace($name));

        return implode('/', \count($segments) > 1 ? \array_slice($segments, 0, -1) : $segments);
    }

    /**
     * @return list<string> the top-level directories of the components of the chain
     */
    private function categories(): array
    {
        $categories = [];

        foreach ($this->templateChain->directories() as $templateDirectory) {
            $componentDirectory = $templateDirectory . '/components';

            if (!is_dir($componentDirectory)) {
                continue;
            }

            foreach ((new Finder())->directories()->depth(0)->in($componentDirectory) as $directory) {
                if (self::TOOLKIT_DIRECTORY !== $directory->getFilename()) {
                    $categories[$directory->getFilename()] = true;
                }
            }
        }

        return array_keys($categories);
    }

    /**
     * `theme_flexy:Fields:Input:Base` is the component `Fields:Input:Base` of the theme.
     */
    public static function withoutNamespace(string $name): string
    {
        return preg_replace('/^theme_[A-Za-z0-9_]+:/', '', $name) ?? $name;
    }
}
