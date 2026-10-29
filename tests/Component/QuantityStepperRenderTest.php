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
 * The quantity stepper moves by its step and, for a quantity sold by measure, takes decimals:
 * a number field for pieces, a text field with a decimal keyboard for a weight (a number field
 * empties "1,25" when the browser does not write decimals with a comma), its bounds handed to
 * the controller, and its unit read with the field.
 */
final class QuantityStepperRenderTest extends KernelTestCase
{
    public function testAQuantityOfPiecesIsANumberFieldThatMovesByItsStep(): void
    {
        $html = $this->render(['step' => 6, 'input:name' => 'cart_add[quantity]', 'input:value' => '6', 'input:min' => '6', 'input:max' => '36']);
        $input = $this->input($html);

        self::assertStringContainsString('type="number"', $input);
        self::assertStringContainsString('inputmode="numeric"', $input);
        self::assertStringContainsString('step="6"', $input);
        self::assertStringContainsString('min="6"', $input);
        self::assertStringContainsString('max="36"', $input);
        self::assertMatchesRegularExpression('/data-[\w-]*quantity-step-value="6"/i', $html);
    }

    public function testAQuantitySoldByMeasureIsATextFieldWithADecimalKeyboardAndItsUnit(): void
    {
        $html = $this->render(['step' => '0.25', 'decimals' => 2, 'unit' => 'kg', 'input:name' => 'cart_add[quantity]', 'input:value' => '1.25', 'input:min' => '0.25']);
        $input = $this->input($html);

        self::assertStringContainsString('type="text"', $input);
        self::assertStringContainsString('inputmode="decimal"', $input);
        self::assertStringNotContainsString('min=', $input, 'a text field has no min, the controller holds it');
        self::assertStringNotContainsString('step=', $input);
        self::assertMatchesRegularExpression('/data-[\w-]*quantity-step-value="0.25"/i', $html);
        self::assertMatchesRegularExpression('/data-[\w-]*quantity-decimals-value="2"/i', $html);
        self::assertMatchesRegularExpression('/data-[\w-]*quantity-min-value="0.25"/i', $html);
        self::assertStringContainsString('aria-describedby="cart_add-quantity-unit"', $input);
        self::assertMatchesRegularExpression('/<span id="cart_add-quantity-unit" class="ButtonQuantity__unit">kg<\/span>/', $html);
    }

    public function testAStepGivenAsAnAttributeOfTheFieldStillCounts(): void
    {
        $input = $this->input($this->render(['input:step' => '2', 'input:value' => '2']));

        self::assertSame(1, substr_count($input, 'step='));
        self::assertStringContainsString('step="2"', $input);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender('Molecules:Button:Quantity', $props);
    }

    private function input(string $html): string
    {
        self::assertSame(1, preg_match('/<input\b[^>]*>/s', $html, $match));

        return $match[0];
    }
}
