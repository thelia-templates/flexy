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

namespace FlexyBundle\Tests\Unit\Showcase;

use FlexyBundle\Showcase\FlexyComponents;
use FlexyBundle\Showcase\FlexyExampleProvider;
use FlexyBundle\Template\FrontTemplateChain;
use FlexyBundle\Toolkit\ComponentStatus;
use FlexyBundle\Toolkit\ModuleStories;
use FlexyBundle\Toolkit\Story;
use FlexyBundle\Toolkit\StoryFinder;
use FlexyBundle\Toolkit\StoryProviderInterface;
use OpenStudio\UxShowcaseBundle\Example\Example;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Every story of the toolkit becomes one free example: attached to the `Base` component of its
 * directory when there is one, a documentation page of its category otherwise. A story a module
 * registers is a documentation page of its own category.
 */
final class FlexyExampleProviderTest extends TestCase
{
    private const THEME = 'flexy';

    protected function setUp(): void
    {
        if (!class_exists(Example::class)) {
            self::markTestSkipped('openstudio/ux-showcase-bundle is a suggestion of the theme and is not installed.');
        }
    }

    public function testAStoryIsAttachedToTheBaseComponentOfItsDirectory(): void
    {
        $example = $this->examplesBySlug(['Molecules:Button:Base'])['molecules-button'] ?? null;

        self::assertNotNull($example);
        self::assertSame('Molecules:Button:Base', $example->component);
        self::assertNull($example->group);
        self::assertTrue($example->isFree());
    }

    public function testTheExampleIsTheWholeSourceOfTheStory(): void
    {
        $example = $this->examplesBySlug(['Molecules:Button:Base'])['molecules-button'];

        self::assertSame(file_get_contents(FrontTemplateChain::ownDirectory().'/components/Molecules/Button/toolkit.html.twig'), $example->source);
        self::assertSame('Button', $example->title);
    }

    public function testAStoryWhoseDirectoryHasNoBaseComponentIsADocumentationPageOfItsCategory(): void
    {
        $examples = $this->examplesBySlug(['Molecules:Button:Base']);

        self::assertNull($examples['atoms-colors']->component);
        self::assertSame('Atoms', $examples['atoms-colors']->group);
        // `Molecules/Tag` has a story and, here, no Base component among the ones the library lists.
        self::assertNull($examples['molecules-tag']->component);
        self::assertSame('Molecules', $examples['molecules-tag']->group);
    }

    public function testEveryStoryOfTheChainIsAnExampleWithAValidUniqueSlug(): void
    {
        $examples = iterator_to_array($this->provider([])->getExamples(), false);
        $stories = array_merge(...array_values($this->finder()->groupedComponents()));
        $slugs = array_map(static fn (Example $example): string => $example->slug, $examples);

        self::assertCount(\count($stories), $examples);
        self::assertSame(array_column($stories, 'slug'), $slugs);
        self::assertSame($slugs, array_unique($slugs));

        foreach ($slugs as $slug) {
            self::assertMatchesRegularExpression(Example::SLUG_PATTERN, $slug);
        }
    }

    public function testAHiddenStoryIsNotAnExample(): void
    {
        $examples = $this->examplesBySlug([], $this->moduleProvider(
            new Story('CMS', 'Gone', '@Module/toolkit/Gone.html.twig', __FILE__, ComponentStatus::HIDDEN),
            new Story('CMS', 'Here', '@Module/toolkit/Here.html.twig', __FILE__, ComponentStatus::READY),
        ));

        self::assertArrayNotHasKey('cms-gone', $examples);
        self::assertArrayHasKey('cms-here', $examples);
    }

    public function testAThemeStoryDeclaredHiddenIsNotAnExample(): void
    {
        $child = uniqid('zz-showcase-', false);
        $link = THELIA_TEMPLATE_DIR.'frontOffice'.DS.$child;
        $directory = \dirname(FrontTemplateChain::ownDirectory()).DS.$child;
        $filesystem = new Filesystem();

        try {
            $filesystem->dumpFile($directory.DS.'template.xml', '<?xml version="1.0" encoding="UTF-8"?><template xmlns="http://thelia.net/schema/dic/template"><descriptive locale="en"><title>Child</title></descriptive><parent>flexy</parent><languages><language>en_US</language></languages><version>1.0.0</version><stability>prod</stability></template>');
            $filesystem->dumpFile($directory.DS.StoryFinder::STATUS_FILE, "<?php\n\nreturn ['Molecules/Button' => 'hidden'];\n");

            if (!is_dir($link)) {
                $filesystem->symlink($directory, $link);
            }

            $moduleStories = new ModuleStories([]);
            $examples = iterator_to_array((new FlexyExampleProvider(
                new StoryFinder(new FrontTemplateChain($child), $moduleStories),
                $moduleStories,
                new class([]) extends FlexyComponents {
                    public function __construct(private readonly array $names)
                    {
                    }

                    public function names(): array
                    {
                        return $this->names;
                    }
                },
            ))->getExamples(), false);
            $slugs = array_map(static fn (Example $example): string => $example->slug, $examples);

            self::assertNotContains('molecules-button', $slugs);
            self::assertContains('molecules-tag', $slugs);
        } finally {
            $filesystem->remove([$link, $directory]);
        }
    }

    public function testAModuleStoryIsADocumentationPageOfTheCategoryItDeclares(): void
    {
        $examples = $this->examplesBySlug([], $this->moduleProvider(
            new Story('CMS', 'All sections', '@Module/toolkit/All.html.twig', __FILE__),
            new Story('CMS', 'Icons', '@Module/toolkit/Icons.html.twig', __FILE__),
        ));

        foreach (['cms-all-sections', 'cms-icons'] as $slug) {
            self::assertNull($examples[$slug]->component, $slug);
            self::assertSame('CMS', $examples[$slug]->group, $slug);
        }

        self::assertSame('All sections', $examples['cms-all-sections']->title);
        self::assertSame(file_get_contents(__FILE__), $examples['cms-icons']->source);
    }

    public function testAModuleStoryIsNeverAttachedToAComponentOfTheTheme(): void
    {
        $examples = $this->examplesBySlug(['Modules:Callout:Base'], $this->moduleProvider(
            // Even a module that files its story under the theme's own components directory.
            new Story('Modules', 'Callout', '@Flexy/Modules/Callout/toolkit.html.twig', __FILE__),
        ));

        self::assertNull($examples['modules-callout']->component);
        self::assertSame('Modules', $examples['modules-callout']->group);
    }

    public function testTheExamplesThatNeedDataAreMarkedAndTheOthersDecideNothing(): void
    {
        $examples = $this->examplesBySlug([]);

        self::assertTrue($examples['layouts-header']->preview->needsData);
        self::assertTrue($examples['organisms-productcard']->preview->needsData);
        self::assertNull($examples['molecules-button']->preview->needsData);
    }

    public function testEveryExampleListedAsNeedingDataIsAStoryThatExists(): void
    {
        $examples = $this->examplesBySlug([], $this->moduleProvider(
            new Story('CMS', 'All sections', '@Module/toolkit/All.html.twig', __FILE__),
        ));

        foreach (FlexyExampleProvider::NEEDS_DATA as $slug) {
            self::assertArrayHasKey($slug, $examples, 'This story is gone: drop it from NEEDS_DATA.');
        }
    }

    /**
     * @param list<string> $componentNames the components the library lists
     *
     * @return array<string, Example>
     */
    private function examplesBySlug(array $componentNames, ?StoryProviderInterface $moduleProvider = null): array
    {
        $examples = [];

        foreach ($this->provider($componentNames, $moduleProvider)->getExamples() as $example) {
            $examples[$example->slug] = $example;
        }

        return $examples;
    }

    /**
     * @param list<string> $componentNames
     */
    private function provider(array $componentNames, ?StoryProviderInterface $moduleProvider = null): FlexyExampleProvider
    {
        $components = new class($componentNames) extends FlexyComponents {
            /** @param list<string> $componentNames */
            public function __construct(private readonly array $componentNames)
            {
            }

            public function names(): array
            {
                return $this->componentNames;
            }
        };

        $moduleStories = new ModuleStories(null === $moduleProvider ? [] : [$moduleProvider]);

        return new FlexyExampleProvider(new StoryFinder(new FrontTemplateChain(self::THEME), $moduleStories), $moduleStories, $components);
    }

    private function finder(): StoryFinder
    {
        return new StoryFinder(new FrontTemplateChain(self::THEME), new ModuleStories([]));
    }

    private function moduleProvider(Story ...$stories): StoryProviderInterface
    {
        return new class($stories) implements StoryProviderInterface {
            /** @param list<Story> $stories */
            public function __construct(private readonly array $stories)
            {
            }

            public function stories(): array
            {
                return $this->stories;
            }
        };
    }
}
