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

use FlexyBundle\Showcase\ShowcaseViewports;
use PHPUnit\Framework\TestCase;

/**
 * The showcase previews at the breakpoints of the theme, in the words the toolkit uses.
 */

final class ShowcaseViewportsTest extends TestCase
{
    public function testTheResponsivePreviewComesFirstThenEachBreakpointInPixelsNarrowestFirst(): void
    {
        $viewports = ShowcaseViewports::fromCss('--breakpoint-lg: 64rem; --breakpoint-xs: 24.375rem; --breakpoint-md: 48rem;');

        self::assertSame(['responsive', 'Mobile M', 'Tablet', 'Small Desktop'], array_keys($viewports));
        self::assertSame([null, 390, 768, 1024], array_values($viewports));
    }

    public function testABreakpointTheToolkitDoesNotLabelKeepsItsName(): void
    {
        $viewports = ShowcaseViewports::fromCss('--breakpoint-3xl: 120rem;');

        self::assertSame(['responsive' => null, '3xl' => 1920], $viewports);
    }

    public function testNothingButTheResponsivePreviewWhenThereIsNoBreakpoint(): void
    {
        self::assertSame(['responsive' => null], ShowcaseViewports::fromCss('--color-brand: red;'));
    }

    public function testTheBreakpointsOfThisThemeAreRead(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/styles/variables.css');

        $viewports = ShowcaseViewports::fromCss($css);

        self::assertSame(['responsive' => null, 'Mobile S' => 320], \array_slice($viewports, 0, 2, true));
        self::assertSame(768, $viewports['Tablet']);
        self::assertSame(1366, $viewports['Desktop']);
    }
}
