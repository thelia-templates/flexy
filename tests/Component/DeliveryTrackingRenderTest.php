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

/** The button that opens the carrier page following the parcel of an order. */
final class DeliveryTrackingRenderTest extends KernelTestCase
{
    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender('Organisms:DeliveryTracking:Base', $props);
    }

    private function trackingLink(string $html): string
    {
        preg_match('/<a[^>]*data-testid="delivery-tracking-link"[^>]*>/', $html, $link);

        self::assertNotEmpty($link, 'the component renders the tracking link');

        return $link[0];
    }

    public function testTheTrackingLinkOpensTheCarrierPage(): void
    {
        $html = $this->render(['statusCode' => 'sent', 'trackingRef' => '6A12', 'trackLink' => 'https://carrier.example/track?parcel=6A12&lang=fr']);

        self::assertStringContainsString('href="https://carrier.example/track?parcel=6A12&amp;lang=fr"', $this->trackingLink($html));
        self::assertStringContainsString('6A12', $html);
    }

    /** The carrier site gets a tab of its own, and no handle on the shop page. */
    public function testTheCarrierPageOpensInANewTabWithoutAccessToTheShop(): void
    {
        $link = $this->trackingLink($this->render(['statusCode' => 'sent', 'trackingRef' => '6A12', 'trackLink' => 'https://carrier.example/6A12']));

        self::assertStringContainsString('target="_blank"', $link);
        self::assertMatchesRegularExpression('/rel="[^"]*noopener[^"]*"/', $link);
    }

    public function testWithoutLinkTheNumberIsShownAndNoEmptyButton(): void
    {
        foreach ([null, ''] as $noLink) {
            $html = $this->render(['statusCode' => 'sent', 'trackingRef' => '6A12', 'trackLink' => $noLink]);

            self::assertStringContainsString('6A12', $html);
            self::assertStringNotContainsString('delivery-tracking-link', $html);
        }
    }
}
