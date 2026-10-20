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

/** A product without any sale element renders its selector, as a product that cannot be bought, instead of failing. */
final class PseSelectorNoSaleElementTest extends KernelTestCase
{
    public function testAProductWithoutSaleElementRendersWithStockManaged(): void
    {
        $html = $this->render(['stockManaged' => true]);

        self::assertStringContainsString('This product combination is not available', $html);
        self::assertStringNotContainsString('PseSelector-price', $html);
    }

    public function testAProductWithoutSaleElementRendersWithoutStockManagement(): void
    {
        $html = $this->render(['stockManaged' => false]);

        self::assertStringContainsString('This product combination is not available', $html);
        self::assertStringNotContainsString('PseSelector-price', $html);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props): string
    {
        /** @var FormFactoryInterface $forms */
        $forms = self::getContainer()->get('form.factory');
        $form = $forms->createNamedBuilder('cart_add', FormType::class, null, ['csrf_protection' => false]);

        foreach (['product', 'product_sale_elements_id', 'append', 'newness', 'quantity'] as $field) {
            $form->add($field, HiddenType::class);
        }

        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');

        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender('Organisms:PseSelector:Base', [
            'form' => $form->getForm()->createView(),
            'currentPse' => null,
            ...$props,
        ]);
    }
}
