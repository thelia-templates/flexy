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

/** The accessible name of the dialog, taken from the title its host renders. */
final class ModalRenderTest extends KernelTestCase
{
    private function dialog(array $props): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        preg_match('/<dialog[^>]*>/', $renderer->createAndRender('Molecules:Modal:Base', $props), $dialog);

        self::assertNotEmpty($dialog, 'the modal renders its dialog');

        return $dialog[0];
    }

    public function testTheDialogIsNamedByTheTitleItIsGiven(): void
    {
        self::assertStringContainsString(
            'aria-labelledby="confirmDeleteTitle"',
            $this->dialog(['id' => 'confirmDelete', 'labelledby' => 'confirmDeleteTitle']),
        );
    }

    public function testADialogGivenNoTitlePointsAtNothing(): void
    {
        self::assertStringNotContainsString('aria-labelledby', $this->dialog(['id' => 'confirmDelete']));
    }
}
