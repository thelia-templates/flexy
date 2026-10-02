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

/** The zoom overlay as the server renders it, before its controller fills it. */
final class ImageZoomRenderTest extends KernelTestCase
{
    private function render(array $props): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender('Molecules:ImageZoom:Base', $props);
    }

    /** The two attributes are the whole contract with the host, which rewrites them. */
    public function testTheVisualIsHandedOverThroughTwoAttributes(): void
    {
        $html = $this->render(['src' => '/media/zoom/sofa.jpg', 'alt' => 'The sofa from the front']);

        self::assertStringContainsString('data-image-zoom-src="/media/zoom/sofa.jpg"', $html);
        self::assertStringContainsString('data-image-zoom-alt="The sofa from the front"', $html);
    }

    /** Rendering the visual would download it with the page, whether the shopper opens it or not. */
    public function testTheVisualIsNotRenderedWithThePage(): void
    {
        $html = $this->render(['src' => '/media/zoom/sofa.jpg']);

        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('/media/zoom/sofa.jpg"', preg_replace('/data-image-zoom-src="[^"]*"/', '', $html));
    }

    public function testTheOverlayAndEachOfItsControlsAreNamed(): void
    {
        $html = $this->render(['src' => '/media/zoom/sofa.jpg']);

        self::assertMatchesRegularExpression('/<dialog[^>]*aria-label="[^"]+"/', $html);

        preg_match_all('/<button[^>]*>/', $html, $buttons);

        self::assertCount(3, $buttons[0], 'close, zoom out, zoom in');

        foreach ($buttons[0] as $button) {
            self::assertStringContainsString('type="button"', $button);
            self::assertMatchesRegularExpression('/aria-label="[^"]+"/', $button);
        }
    }
}
