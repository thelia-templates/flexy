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

use FlexyBundle\Toolkit\StoryFinder;
use OpenStudio\UxShowcaseBundle\Example\PreviewOptions;
use OpenStudio\UxShowcaseBundle\Library\ComponentLibraryInterface;
use OpenStudio\UxShowcaseBundle\Library\ComponentReference;
use OpenStudio\UxShowcaseBundle\Library\ComponentStatus;

/**
 * The components of the theme in the component showcase, grouped like the toolkit: one group per
 * top-level directory of `components/`, alphabetical, `Forms` and `Layouts` last, then the
 * categories the modules add.
 *
 * The status of a component is the one of its directory in the toolkit (`ready`, `waiting`,
 * `hidden`), whatever template of the chain declares it; none when the toolkit declares none.
 */
final readonly class FlexyLibrary implements ComponentLibraryInterface
{
    public const string NAME = 'flexy';

    /** A template of the theme: the bare page loads the stylesheets and scripts of the shop pages. */
    public const string BARE_TEMPLATE = '@Flexy/Toolkit/_showcase-bare.html.twig';

    public function __construct(
        private FlexyComponents $components,
        private StoryFinder $storyFinder,
        private FlexyExampleProvider $exampleProvider,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'Flexy';
    }

    public function getComponents(): array
    {
        $references = [];

        foreach ($this->components->names() as $name) {
            $directory = FlexyComponents::directoryOf($name);
            $segments = explode('/', $directory);

            $references[] = new ComponentReference(
                $name,
                $segments[0],
                $this->statusOf($directory),
                self::labelOf($name, $segments),
            );
        }

        return $references;
    }

    public function getGroups(): array
    {
        return array_values(array_unique([
            ...$this->exampleProvider->pageGroups(),
            ...array_map('strval', array_keys($this->storyFinder->groupedComponents())),
        ]));
    }

    public function getExampleProviders(): iterable
    {
        yield $this->exampleProvider;
    }

    public function getWrapperTemplate(): ?string
    {
        return null;
    }

    public function getBareTemplate(): ?string
    {
        return self::BARE_TEMPLATE;
    }

    public function getPreviewOptions(): PreviewOptions
    {
        return new PreviewOptions();
    }

    /**
     * Null where the toolkit declares nothing: the showcase then shows no badge, as the toolkit does.
     */
    private function statusOf(string $directory): ?ComponentStatus
    {
        $status = $this->storyFinder->statusOf($directory);

        return null === $status ? null : ComponentStatus::tryFrom($status);
    }

    /**
     * `Molecules:Button:Base` reads "Button", `Molecules:Button:Quantity` reads "Button / Quantity".
     *
     * @param list<string> $directorySegments
     */
    private static function labelOf(string $name, array $directorySegments): string
    {
        $variant = substr(FlexyComponents::withoutNamespace($name), \strlen(implode(':', $directorySegments)) + 1);
        $label = implode(' / ', \array_slice($directorySegments, 1));

        if ('' === $label) {
            return '' === $variant ? $directorySegments[0] : $variant;
        }

        return 'Base' === $variant || '' === $variant ? $label : $label . ' / ' . $variant;
    }
}
