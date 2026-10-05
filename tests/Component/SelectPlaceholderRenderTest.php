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
 * A form hands an unchosen select over as '' (the ChoiceType view), and the placeholder was
 * selected for null only: a disabled placeholder that is not selected lets the browser show
 * and post the first choice, as if the buyer had picked it (the first department of a list
 * of states, for an address in France).
 */
final class SelectPlaceholderRenderTest extends KernelTestCase
{
    public function testAnEmptyValueKeepsThePlaceholderSelected(): void
    {
        self::assertMatchesRegularExpression('/<option value=""\s+selected disabled>Choose<\/option>/', $this->render('', true));
    }

    public function testAnOptionalSelectLetsThePlaceholderBeChosenBack(): void
    {
        $html = $this->render('', false);

        self::assertMatchesRegularExpression('/<option value=""\s+selected>Choose<\/option>/', $html);
    }

    public function testAChosenValueIsSelectedInsteadOfThePlaceholder(): void
    {
        $html = $this->render('2', true);

        self::assertMatchesRegularExpression('/<option value=""\s+disabled>Choose<\/option>/', $html);
        self::assertMatchesRegularExpression('/<option value="2"\s+selected>B<\/option>/', $html);
    }

    private function render(string $value, bool $required): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender('Fields:Select:Base', [
            'id' => 'state',
            'label' => 'State',
            'placeholder' => 'Choose',
            'required' => $required,
            'value' => $value,
            'choices' => [['value' => '1', 'label' => 'A'], ['value' => '2', 'label' => 'B']],
        ]);
    }
}
