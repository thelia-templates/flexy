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
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\UX\TwigComponent\ComponentRendererInterface;

/** A value no combination can be bought in is offered as a disabled pill. */
final class PseSelectorRenderTest extends KernelTestCase
{
    public function testAnUnavailableValueIsADisabledPill(): void
    {
        $pills = $this->pills([
            ['id' => 1, 'label' => 'Small', 'available' => false],
            ['id' => 2, 'label' => 'Medium', 'available' => true],
            ['id' => 3, 'label' => 'Large'],
        ]);

        self::assertTrue($pills['Small']);
        self::assertFalse($pills['Medium']);
        self::assertFalse($pills['Large'], 'a value whose availability is not known stays selectable');
    }

    /**
     * @param list<array<string, mixed>> $values
     *
     * @return array<string, bool> label => whether the pill is disabled
     */
    private function pills(array $values): array
    {
        /** @var FormFactoryInterface $forms */
        $forms = self::getContainer()->get('form.factory');
        $form = $forms->createNamedBuilder('cart_add', FormType::class, null, ['csrf_protection' => false]);

        foreach (['product', 'product_sale_elements_id', 'append', 'newness', 'quantity'] as $field) {
            $form->add($field, HiddenType::class);
        }

        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');
        $html = $renderer->createAndRender('Organisms:PseSelector:Base', [
            'form' => $form->getForm()->createView(),
            'productAttrs' => [['id' => 7, 'label' => 'Size', 'values' => $values]],
            'currentCombination' => [7 => 2],
            // No price to draw: the pills are what is looked at.
            'noAvailablePse' => true,
        ]);

        preg_match_all('/<button\b([^>]*\bdata-live-value-param="\d+"[^>]*)>.*?<\/button>/s', $html, $buttons, \PREG_SET_ORDER);
        $pills = [];

        foreach ($buttons as [$button, $attributes]) {
            $label = trim(strip_tags($button));
            $pills[$label] = 1 === preg_match('/\sdisabled(?:[\s=>]|$)/', $attributes);
        }

        self::assertSame(['Small', 'Medium', 'Large'], array_keys($pills));

        return $pills;
    }
}
