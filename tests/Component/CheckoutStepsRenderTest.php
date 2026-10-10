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
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\UX\TwigComponent\ComponentRendererInterface;

/**
 * A step of the checkout configuration carries the title the merchant wrote, already in the
 * language of the page: the bar prints it as it is. A wording written in the template still
 * goes through the catalogue.
 */
final class CheckoutStepsRenderTest extends KernelTestCase
{
    private function labels(array $steps): string
    {
        /** @var LocaleSwitcher $locales */
        $locales = self::getContainer()->get('translation.locale_switcher');

        return $locales->runWithLocale('fr_FR', static function () use ($steps): string {
            /** @var ComponentRendererInterface $renderer */
            $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

            return $renderer->createAndRender('Molecules:CheckoutSteps:Base', ['steps' => $steps, 'current' => 1, 'noCart' => true]);
        });
    }

    public function testATitleFromTheConfigurationIsPrintedAsItIs(): void
    {
        $html = $this->labels([['code' => 'delivery', 'text' => 'Delivery', 'translate' => false]]);

        self::assertStringContainsString('Delivery', $html);
        self::assertStringNotContainsString('Livraison', $html);
    }

    public function testAWordingWithoutTheFlagGoesThroughTheCatalogue(): void
    {
        self::assertStringContainsString('Livraison', $this->labels([['text' => 'Delivery']]));
    }
}
