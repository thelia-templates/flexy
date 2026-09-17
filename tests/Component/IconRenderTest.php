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

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\ComponentRendererInterface;

/**
 * What the icon renderer does to the SVG files the theme ships. ux-icons applies its
 * configured attributes over the ones a file carries, so a default fill replaces what the
 * file declares instead of filling in a missing one.
 */
final class IconRenderTest extends KernelTestCase
{
    /** The rendered markup of one icon, as the theme serves it. */
    private function renderIcon(string $icon): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        $html = $renderer->createAndRender('Molecules:Tag:Base', ['customText' => 'x', 'icon' => $icon]);

        self::assertMatchesRegularExpression('/<svg[^>]*>/', $html, 'the icon is rendered');

        return $html;
    }

    public function testAnIconKeepsTheFillItsFileDeclares(): void
    {
        preg_match('/<svg[^>]*>/', $this->renderIcon('price-tag'), $svg);

        self::assertStringContainsString('fill="none"', $svg[0]);
        self::assertStringNotContainsString('fill="currentColor"', $svg[0]);
    }

    /** The same contract across the whole set rather than on one file. */
    public function testNoIconIsRenderedWithAFillItsFileDoesNotDeclare(): void
    {
        $iconDir = \dirname(__DIR__, 2) . '/assets/icons';
        $drifted = [];

        foreach (glob($iconDir . '/*.svg') ?: [] as $path) {
            preg_match('/<svg[^>]*\bfill="([^"]*)"/', (string) file_get_contents($path), $declared);
            preg_match('/<svg[^>]*>/', $this->renderIcon(basename($path, '.svg')), $rendered);
            preg_match('/\bfill="([^"]*)"/', $rendered[0] ?? '', $produced);

            if (($declared[1] ?? null) !== ($produced[1] ?? null)) {
                $drifted[basename($path)] = ($declared[1] ?? '(none)') . ' -> ' . ($produced[1] ?? '(none)');
            }
        }

        self::assertSame([], $drifted, 'every icon keeps the root fill its file declares');
    }
}
