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

use FlexyBundle\Showcase\ShowcaseBackgrounds;
use PHPUnit\Framework\TestCase;

/**
 * The showcase offers the page colour of the theme, the only background its body lets show.
 */
final class ShowcaseBackgroundsTest extends TestCase
{
    public function testThePageColourOfTheStylesheetIsTheOnlyBackground(): void
    {
        self::assertSame(
            ['theme' => ['color' => '#fff5f1']],
            ShowcaseBackgrounds::fromCss(":root {\n  --color-lightest: #fff5f1;\n  --color-white: #ffffff;\n}"),
        );
    }

    public function testTheLastDeclarationWins(): void
    {
        self::assertSame(['theme' => ['color' => '#123abc']], ShowcaseBackgrounds::fromCss('--color-lightest: #fff5f1; --color-lightest: #123abc;'));
    }

    public function testAnotherVariableWithTheSameBeginningIsNotRead(): void
    {
        self::assertSame([], ShowcaseBackgrounds::fromCss('--color-lightest-soft: #111111;'));
    }

    public function testAValueThatIsNotHexadecimalIsRefused(): void
    {
        self::assertSame([], ShowcaseBackgrounds::fromCss('--color-lightest: red;'));
        self::assertSame([], ShowcaseBackgrounds::fromCss('--color-lightest: #fff5f1" onload="x;'));
    }

    public function testNothingWhenThereIsNoStylesheet(): void
    {
        self::assertSame([], ShowcaseBackgrounds::fromCss(''));
    }

    public function testTheColourOfThisThemeIsRead(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/styles/variables.css');

        self::assertSame(['theme' => ['color' => '#fff5f1']], ShowcaseBackgrounds::fromCss($css));
    }
}
