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

use PHPUnit\Framework\TestCase;

/**
 * The toolkit sets every story in a white canvas with an inner margin; the bare page of the showcase
 * frames the example the same way.
 */
final class ShowcaseBareTemplateTest extends TestCase
{
    public function testTheExampleIsFramedInTheCanvasOfTheToolkit(): void
    {
        $template = (string) file_get_contents(\dirname(__DIR__, 3).'/components/Toolkit/_showcase-bare.html.twig');

        self::assertMatchesRegularExpression('/<div class="Toolkit-canvas">\s*\{\{ content \}\}\s*<\/div>/', $template);
        self::assertStringContainsString("asset('styles/toolkit.css')", $template);
    }
}
