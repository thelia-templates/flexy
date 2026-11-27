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
use FlexyBundle\Template\FrontTemplateChain;
use FlexyBundle\Tests\Support\WithChildTheme;
use OpenStudio\UxShowcaseBundle\Library\ComponentCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\UX\TwigComponent\ComponentFactory;
use Symfony\UX\TwigComponent\ComponentTemplateFinderInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;

/**
 * The components of the theme among everything the catalog lists: the ones under a category
 * directory of `components/`, by the name a page writes.
 */

final class FlexyComponentsTest extends TestCase
{
    use WithChildTheme;

    private const CATEGORIES = ['Atoms', 'Fields', 'Molecules'];
    private const NAMESPACES = ['theme_flexy:'];

    public function testOnlyTheComponentsOfTheThemeCategoriesAreKept(): void
    {
        $names = $this->select(['Molecules:Button:Base', 'BoDataTable', 'UX:Icon', 'TheliaLibraryModule:TheliaLibraryImage', 'Atoms:TaxMention:Base']);

        self::assertSame(['Atoms:TaxMention:Base', 'Molecules:Button:Base'], $names);
    }

    public function testAStoryAndAToolkitPageAreNotComponents(): void
    {
        $names = $this->select(['Molecules:Button:toolkit', 'theme_flexy:Toolkit:welcome', 'theme_flexy:Molecules:Tag:toolkit', 'Molecules:Tag:Base']);

        self::assertSame(['Molecules:Tag:Base'], $names);
    }

    public function testAnAnonymousComponentTakesTheNameAPageWritesWhenTheCatalogKnowsIt(): void
    {
        $names = $this->select(['theme_flexy:Fields:Input:Base'], static fn (string $name): bool => 'Fields:Input:Base' === $name);

        self::assertSame(['Fields:Input:Base'], $names);
    }

    public function testAnAnonymousComponentKeepsItsNamespaceWhenTheCatalogOnlyKnowsItThere(): void
    {
        self::assertSame(['theme_flexy:Fields:Input:Base'], $this->select(['theme_flexy:Fields:Input:Base']));
    }

    public function testAComponentListedWithAndWithoutItsNamespaceIsListedOnce(): void
    {
        $names = $this->select(['theme_flexy:Molecules:Button:Base', 'Molecules:Button:Base'], static fn (): bool => true);

        self::assertSame(['Molecules:Button:Base'], $names);
    }

    public function testTheDirectoryOfAComponentIsWhereItsStatusAndStoryAreKeyed(): void
    {
        self::assertSame('Molecules/Button', FlexyComponents::directoryOf('Molecules:Button:Quantity'));
        self::assertSame('Fields/Input', FlexyComponents::directoryOf('theme_flexy:Fields:Input:Base'));
    }

    public function testTheCategoriesAndTheCatalogAreReadFromTheChainAndTheApplication(): void
    {
        $this->withChildTheme(
            [
                'components/Alien/Thing/Base.html.twig' => '<div>thing</div>',
                'components/Toolkit/welcome.html.twig' => '<div>welcome</div>',
                'components/Atoms/Own/toolkit.html.twig' => '<div>story</div>',
            ],
            function (string $child, string $directory): void {
                $names = $this->names($child, $directory, known: []);

                // A category only the child template has is one of the theme.
                self::assertContains(sprintf('theme_%s:Alien:Thing:Base', str_replace('-', '_', $child)), $names);
                // The pages of the toolkit and the stories are not components.
                self::assertNotContains(sprintf('theme_%s:Toolkit:welcome', str_replace('-', '_', $child)), $names);
                self::assertNotContains(sprintf('theme_%s:Atoms:Own:toolkit', str_replace('-', '_', $child)), $names);
                // What is neither in a category of the chain nor of its namespaces is not the theme's.
                self::assertNotContains('other:Zed:Thing', $names);
                self::assertNotContains('Zed:Thing', $names);
            },
        );
    }

    public function testAnAnonymousComponentTakesTheNameAPageWritesOnlyWhereTheCatalogResolvesIt(): void
    {
        $this->withChildTheme(
            ['components/Alien/Thing/Base.html.twig' => '<div>thing</div>'],
            function (string $child, string $directory): void {
                $namespaced = sprintf('theme_%s:Alien:Thing:Base', str_replace('-', '_', $child));

                $resolved = $this->names($child, $directory, known: ['Alien:Thing:Base']);
                self::assertContains('Alien:Thing:Base', $resolved);
                self::assertNotContains($namespaced, $resolved);

                $unresolved = $this->names($child, $directory, known: []);
                self::assertContains($namespaced, $unresolved);
                self::assertNotContains('Alien:Thing:Base', $unresolved);
            },
        );
    }

    /**
     * @param list<string> $known the anonymous components the catalog resolves by their page name
     *
     * @return list<string>
     */
    private function names(string $child, string $directory, array $known): array
    {
        $other = sys_get_temp_dir().'/flexy-showcase-'.uniqid('', false);
        mkdir($other.'/components/Zed/Thing', 0777, true);
        touch($other.'/components/Zed/Thing.html.twig');

        try {
            $loader = new FilesystemLoader();
            $loader->addPath($directory, 'theme_'.str_replace('-', '_', $child));
            $loader->addPath($other, 'other');

            $finder = new class($known) implements ComponentTemplateFinderInterface {
                /** @param list<string> $known */
                public function __construct(private readonly array $known)
                {
                }

                public function findAnonymousComponentTemplate(string $name): ?string
                {
                    return \in_array($name, $this->known, true) ? $name.'.html.twig' : null;
                }
            };
            $factory = new ComponentFactory($finder, new ServiceLocator([]), PropertyAccess::createPropertyAccessor(), new EventDispatcher(), [], [], new Environment(new ArrayLoader()));

            return (new FlexyComponents(new ComponentCatalog($factory, $loader, []), new FrontTemplateChain($child)))->names();
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($other);
        }
    }

    /**
     * @param list<string> $catalogNames
     *
     * @return list<string>
     */
    private function select(array $catalogNames, ?callable $isKnown = null): array
    {
        return FlexyComponents::select($catalogNames, self::CATEGORIES, self::NAMESPACES, $isKnown ?? static fn (): bool => false);
    }
}
