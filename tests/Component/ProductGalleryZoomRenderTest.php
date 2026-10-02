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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\ComponentRendererInterface;

/**
 * How the gallery hands its slides to the zoom overlay. The test database holds no product
 * visual, so the addresses come out empty: what is checked is which slides carry one.
 */
final class ProductGalleryZoomRenderTest extends KernelTestCase
{
    private const MEDIA = [
        ['type' => 'image', 'id' => 1, 'pseIds' => [], 'alt' => 'The sofa from the front'],
        ['type' => 'video', 'id' => 2, 'pseIds' => [], 'alt' => 'A demonstration', 'provider' => 'youtube', 'embedUrl' => 'https://www.youtube-nocookie.com/embed/x', 'fileUrl' => null, 'thumbnailImageId' => 1],
    ];

    private function render(array $media): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender('Organisms:ProductGallery:Base', ['media' => $media]);
    }

    /** @return list<string> the opening tag of each slide, in order */
    private function slides(string $html): array
    {
        preg_match_all('/<li[^>]*class="splide__slide"[^>]*>/', $html, $slides);

        return $slides[0];
    }

    public function testAnImageSlideCarriesWhatTheOverlayShows(): void
    {
        [$image] = $this->slides($this->render(self::MEDIA));

        self::assertStringContainsString('data-zoom-src=', $image);
        self::assertStringContainsString('data-zoom-alt="The sofa from the front"', $image);
    }

    /** A video has nothing to enlarge: the controller hides the trigger on a slide without an address. */
    public function testAVideoSlideCarriesNothingToEnlarge(): void
    {
        [, $video] = $this->slides($this->render(self::MEDIA));

        self::assertStringNotContainsString('data-zoom-src', $video);
    }

    /** Shown by the gallery controller once it has pointed the overlay at the current slide. */
    public function testTheOverlayIsRenderedHiddenAndOutsideTheMorphedPart(): void
    {
        $crawler = new Crawler($this->render(self::MEDIA));

        self::assertCount(1, $crawler->filter('.ProductGallery-main[data-live-ignore] .ImageZoom[hidden]'));
    }

    public function testAGalleryWithoutAVisualHasNoZoom(): void
    {
        self::assertStringNotContainsString('ImageZoom', $this->render([]));
    }
}
