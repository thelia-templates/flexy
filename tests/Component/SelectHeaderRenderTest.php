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
 * A required select rendered without a label (`form_row(field, {label: false})`, a form that
 * draws its labels itself) printed an empty <label> holding the required star alone, above the
 * field. The text field and the password field render no header without a label; the select
 * now does the same.
 */
final class SelectHeaderRenderTest extends KernelTestCase
{
    public function testARequiredSelectWithoutLabelHasNoHeader(): void
    {
        $html = $this->render(['required' => true]);

        self::assertStringNotContainsString('Select-header', $html);
        self::assertStringNotContainsString('Select-label', $html);
        self::assertStringNotContainsString(' *', $html);
    }

    public function testALabelledRequiredSelectKeepsItsLabelAndStar(): void
    {
        $html = $this->render(['label' => 'Country', 'required' => true]);

        self::assertMatchesRegularExpression('/<label class="Select-label" for="country">Country \*<\/label>/', $html);
    }

    public function testAHelpWithoutLabelKeepsTheHeaderForItsTooltip(): void
    {
        $html = $this->render(['help' => 'Where we deliver']);

        self::assertStringContainsString('Select-header', $html);
        self::assertStringNotContainsString('Select-label', $html);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender('Fields:Select:Base', $props + [
            'id' => 'country',
            'choices' => [['value' => '1', 'label' => 'A'], ['value' => '2', 'label' => 'B']],
        ]);
    }
}
