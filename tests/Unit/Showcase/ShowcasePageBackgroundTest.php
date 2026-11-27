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

use FlexyBundle\Showcase\ShowcasePageBackground;
use PHPUnit\Framework\TestCase;

/**
 * The showcase lays its pages on the lightest colour of the theme, as the toolkit does, and on nothing it cannot vouch for.
 */
final class ShowcasePageBackgroundTest extends TestCase
{
    public function testTheLightestColourOfTheStylesheetIsRead(): void
    {
        self::assertSame('#fff5f1', ShowcasePageBackground::fromCss(":root {\n  --color-lighter: #ffede5;\n  --color-lightest: #fff5f1;\n}"));
    }

    public function testTheLastDeclarationWins(): void
    {
        self::assertSame('#123abc', ShowcasePageBackground::fromCss('--color-lightest: #fff5f1; --color-lightest: #123abc;'));
    }

    public function testAValueThatIsNotHexadecimalIsRefused(): void
    {
        self::assertNull(ShowcasePageBackground::fromCss('--color-lightest: white;'));
        self::assertNull(ShowcasePageBackground::fromCss('--color-lightest: #fff5f1" onload="x;'));
        self::assertNull(ShowcasePageBackground::fromCss(''));
    }

    public function testTheBackgroundOfThisThemeIsRead(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/styles/variables.css');

        self::assertSame('#fff5f1', ShowcasePageBackground::fromCss($css));
    }
}
