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
use FlexyBundle\Showcase\FlexyLibrary;
use FlexyBundle\Template\FrontTemplateChain;
use FlexyBundle\Tests\Support\WithChildTheme;
use FlexyBundle\Toolkit\ModuleStories;
use FlexyBundle\Toolkit\StoryFinder;
use OpenStudio\UxShowcaseBundle\Library\ComponentReference;
use OpenStudio\UxShowcaseBundle\Library\ComponentStatus;
use PHPUnit\Framework\TestCase;

/**
 * The library lists the components the theme owns, grouped and labelled like the toolkit, with the
 * status the toolkit gives their directory.
 */
final class FlexyLibraryTest extends TestCase
{
    use WithChildTheme;

    protected function setUp(): void
    {
        if (!interface_exists(\OpenStudio\UxShowcaseBundle\Library\ComponentLibraryInterface::class)) {
            self::markTestSkipped('openstudio/ux-showcase-bundle is a suggestion of the theme and is not installed.');
        }
    }

    public function testTheBaseComponentOfADirectoryReadsAsTheDirectoryAndAVariantAfterIt(): void
    {
        $references = $this->referencesByName($this->library(['Molecules:Button:Base', 'Molecules:Button:Quantity', 'Atoms:Icon']));

        self::assertSame('Button', $references['Molecules:Button:Base']->label);
        self::assertSame('Button / Quantity', $references['Molecules:Button:Quantity']->label);
        // A component directly under its category reads as its own name.
        self::assertSame('Icon', $references['Atoms:Icon']->label);
        self::assertSame('Atoms', $references['Atoms:Icon']->group);
    }

    public function testAnAnonymousComponentKeepsItsLabelWhateverItsNamespace(): void
    {
        $references = $this->referencesByName($this->library(['theme_flexy:Fields:Input:Base']));

        self::assertSame('Input', $references['theme_flexy:Fields:Input:Base']->label);
        self::assertSame('Fields', $references['theme_flexy:Fields:Input:Base']->group);
    }

    public function testAComponentIsGroupedUnderItsTopLevelDirectory(): void
    {
        $references = $this->referencesByName($this->library(['Molecules:Button:Base', 'Organisms:Summary:Mini']));

        self::assertSame('Molecules', $references['Molecules:Button:Base']->group);
        self::assertSame('Organisms', $references['Organisms:Summary:Mini']->group);
        self::assertSame('Summary / Mini', $references['Organisms:Summary:Mini']->label);
    }

    public function testAComponentTheToolkitDeclaresNoStatusForHasNone(): void
    {
        $references = $this->referencesByName($this->library(['Molecules:Button:Base']));

        self::assertNull($references['Molecules:Button:Base']->status);
    }

    public function testAComponentTheToolkitDeclaresReadyIsReady(): void
    {
        $references = $this->referencesByName($this->library(['Atoms:TaxMention:Base']));

        self::assertSame(ComponentStatus::Ready, $references['Atoms:TaxMention:Base']->status);
    }

    public function testTheStatusOfTheDirectoryIsTheOneOfTheComponent(): void
    {
        $this->withChildTheme(
            [StoryFinder::STATUS_FILE => "<?php\n\nreturn ['Molecules/Button' => 'waiting', 'Molecules/Tag' => 'hidden'];\n"],
            function (string $child): void {
                $references = $this->referencesByName($this->library(['Molecules:Button:Quantity', 'Molecules:Tag:Base', 'Molecules:Card:Base'], $child));

                self::assertSame(ComponentStatus::Waiting, $references['Molecules:Button:Quantity']->status);
                // Hidden stays a status: the showcase is the one that leaves the component out.
                self::assertSame(ComponentStatus::Hidden, $references['Molecules:Tag:Base']->status);
                self::assertNull($references['Molecules:Card:Base']->status);
            },
        );
    }

    public function testTheGroupsAreTheCategoriesOfTheToolkitWithFormsAndLayoutsLast(): void
    {
        $groups = $this->library([])->getGroups();

        self::assertContains('Atoms', $groups);
        self::assertContains('Molecules', $groups);
        self::assertSame(['Forms', 'Layouts'], \array_slice($groups, -2));
    }

    public function testItIsTheLibraryNamedFlexyWithTheBarePageOfTheTheme(): void
    {
        $library = $this->library([]);

        self::assertSame('flexy', $library->getName());
        self::assertSame(FlexyLibrary::BARE_TEMPLATE, $library->getBareTemplate());
        self::assertNull($library->getWrapperTemplate());
    }

    /**
     * @param list<string> $componentNames
     */
    private function library(array $componentNames, string $theme = 'flexy'): FlexyLibrary
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
        $moduleStories = new ModuleStories([]);
        $finder = new StoryFinder(new FrontTemplateChain($theme), $moduleStories);

        return new FlexyLibrary($components, $finder, new FlexyExampleProvider($finder, $moduleStories, $components));
    }

    /**
     * @return array<string, ComponentReference>
     */
    private function referencesByName(FlexyLibrary $library): array
    {
        $references = [];

        foreach ($library->getComponents() as $reference) {
            $references[$reference->name] = $reference;
        }

        return $references;
    }
}
