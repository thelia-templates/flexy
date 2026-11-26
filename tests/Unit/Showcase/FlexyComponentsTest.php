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
use PHPUnit\Framework\TestCase;

/**
 * The components of the theme among everything the catalog lists: the ones under a category
 * directory of `components/`, by the name a page writes.
 */

final class FlexyComponentsTest extends TestCase
{
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
