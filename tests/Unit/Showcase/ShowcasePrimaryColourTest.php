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

use FlexyBundle\Showcase\ShowcasePrimaryColour;
use PHPUnit\Framework\TestCase;

/**
 * The showcase takes the colour of the theme's focus ring, and nothing it cannot vouch for.
 */
final class ShowcasePrimaryColourTest extends TestCase
{
    public function testTheDarkColourOfTheStylesheetIsRead(): void
    {
        self::assertSame('#ff4822', ShowcasePrimaryColour::fromCss(":root {\n  --color-dark: #ff4822;\n  --color-base: #ff7036;\n}"));
    }

    public function testTheLastDeclarationWins(): void
    {
        self::assertSame('#123abc', ShowcasePrimaryColour::fromCss('--color-dark: #ff4822; --color-dark: #123abc;'));
    }

    public function testAnotherVariableWithTheSameBeginningIsNotRead(): void
    {
        self::assertNull(ShowcasePrimaryColour::fromCss('--color-dark-soft: #111111; --color-base: #ff7036;'));
    }

    public function testAValueThatIsNotHexadecimalIsRefused(): void
    {
        self::assertNull(ShowcasePrimaryColour::fromCss('--color-dark: red;'));
        self::assertNull(ShowcasePrimaryColour::fromCss('--color-dark: var(--color-base);'));
        self::assertNull(ShowcasePrimaryColour::fromCss('--color-dark: #ff4822" onload="x;'));
    }

    public function testNothingWhenThereIsNoStylesheet(): void
    {
        self::assertNull(ShowcasePrimaryColour::fromCss(''));
    }

    public function testTheColourOfThisThemeIsRead(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/styles/variables.css');

        self::assertSame('#ff4822', ShowcasePrimaryColour::fromCss($css));
    }
}
