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

namespace FlexyBundle\Tests\Component;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\ComponentRendererInterface;

/**
 * A component left without one of its variants (no colour, no size, no status) renders
 * with its default classes and without a deprecation. The variant is null then, and
 * html_cva reads a null variant as an array offset, which PHP 8.5 deprecates. The
 * deprecation is raised inside Twig, which this suite ignores as indirect, so the test
 * collects it itself.
 */
final class NullVariantRenderTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function componentsWithoutTheirVariant(): iterable
    {
        yield 'button without colour nor size' => ['Molecules:Button:Base', ['text' => 'Home'], 'button-style'];
        yield 'quantity button without colour' => ['Molecules:Button:Quantity', [], 'ButtonQuantity'];
        yield 'header button without colour' => ['Molecules:HeaderButton:Base', ['text' => 'Cart'], 'HeaderButton'];
        yield 'link without size' => ['Molecules:Link:Base', ['href' => '/', 'customText' => 'Home'], 'Link'];
        yield 'countdown without size' => ['Molecules:Countdown:Base', ['remainingSeconds' => 60], 'Countdown'];
        yield 'tag without colour' => ['Molecules:Tag:Base', ['customText' => 'Blue'], 'Tag'];
        yield 'order tag without status' => ['Molecules:Tag:Order', ['customText' => 'Paid'], 'OrderTag'];
        yield 'return tag without status' => ['Molecules:Tag:OrderReturn', ['customText' => 'Requested'], 'OrderReturnTag'];
        yield 'input without size' => ['Fields:Input:Base', ['name' => 'email'], 'Input'];
        yield 'password without size' => ['Fields:Input:Password', ['name' => 'password'], 'Input'];
        yield 'text area without size' => ['Fields:TextArea:Base', ['name' => 'message'], 'TextArea'];
        yield 'select without size nor state' => ['Fields:Select:Base', ['choices' => []], 'Select'];
    }

    /**
     * @param array<string, mixed> $props
     */
    #[DataProvider('componentsWithoutTheirVariant')]
    public function testAComponentWithoutItsVariantRendersItsDefaultClasses(string $component, array $props, string $expectedClass): void
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        $deprecations = [];
        set_error_handler(static function (int $level, string $message, string $file, int $line) use (&$deprecations): bool {
            $deprecations[] = \sprintf('%s (%s:%d)', $message, $file, $line);

            return true;
        }, \E_DEPRECATED | \E_USER_DEPRECATED);

        try {
            $html = $renderer->createAndRender($component, $props);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $deprecations);
        self::assertStringContainsString($expectedClass, $html);
    }
}
