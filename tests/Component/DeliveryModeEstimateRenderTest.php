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
 * The delivery interval a carrier card shows under its title, from the two dates the
 * delivery option carries. Nothing is shown while nothing fills them.
 */
final class DeliveryModeEstimateRenderTest extends KernelTestCase
{
    public function testACardShowsBothBoundsOfTheInterval(): void
    {
        $estimate = $this->estimateOf(['earliestDelivery' => '2026-09-04', 'latestDelivery' => '2026-09-08']);

        self::assertNotNull($estimate);
        self::assertStringContainsString('4 September', $estimate);
        self::assertStringContainsString('8 September', $estimate);
    }

    public function testTwoEqualBoundsAreShownAsOneDate(): void
    {
        $estimate = $this->estimateOf(['earliestDelivery' => '2026-09-04', 'latestDelivery' => '2026-09-04']);

        self::assertNotNull($estimate);
        self::assertSame(1, substr_count($estimate, '4 September'));
    }

    public function testASingleBoundStandsForBoth(): void
    {
        $estimate = $this->estimateOf(['latestDelivery' => '2026-09-08']);

        self::assertNotNull($estimate);
        self::assertSame(1, substr_count($estimate, '8 September'));
    }

    public function testACardWithoutDatesShowsNoEstimate(): void
    {
        self::assertNull($this->estimateOf([]));
        self::assertNull($this->estimateOf(['earliestDelivery' => '', 'latestDelivery' => null]));
    }

    /**
     * @param array<string, mixed> $dates
     */
    private function estimateOf(array $dates): ?string
    {
        // `lang_code` belongs to the context the Thelia parser hands a page template.
        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');

        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        $html = $renderer->createAndRender('Organisms:DeliveryMode:Base', [
            'title' => 'Colissimo',
            'optionCode' => 'colissimo',
            'moduleId' => 1,
            'price' => '4.90',
        ] + $dates);

        if (!preg_match('/<span class="DeliveryMode-estimate"[^>]*>(.*?)<\/span>/s', $html, $match)) {
            return null;
        }

        return trim((string) preg_replace('/\s+/', ' ', $match[1]));
    }
}
