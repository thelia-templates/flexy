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

/**
 * The selector tells what the core says may be sold: a pre-order is orderable, with its date, up to its limit;
 * no limit published means no bound; a sale mode that refuses at zero keeps the sold-out sheet.
 */
final class PseSelectorPreorderRenderTest extends KernelTestCase
{
    public function testAPreorderBelowItsLimitIsOrderableUpToTheLimitWithItsDate(): void
    {
        $html = $this->render(['availableQuantity' => 10.0, 'isPreorder' => true, 'restockDate' => '2026-12-15'], remaining: 10);

        self::assertStringContainsString('Pre-order', $html);
        self::assertStringContainsString('StockTag--preorder', $html);
        self::assertStringContainsString('Restock expected on December 15, 2026', $html);
        self::assertMatchesRegularExpression('/<input[^>]*\bmax="10"/', $html);
        self::assertStringNotContainsString('Out of stock', $html);
        self::assertStringContainsString('Add to cart', $html);
    }

    public function testTheDateIsWrittenInTheLanguageOfTheVisitor(): void
    {
        $html = $this->render(['availableQuantity' => 10.0, 'isPreorder' => true, 'restockDate' => '2026-12-15'], remaining: 10, locale: 'fr');

        self::assertStringContainsString('15 décembre 2026', $html);
    }

    public function testAPreorderWithoutDateMentionsNoDate(): void
    {
        $html = $this->render(['availableQuantity' => 10.0, 'isPreorder' => true, 'restockDate' => null], remaining: 10);

        self::assertStringContainsString('Pre-order', $html);
        self::assertStringNotContainsString('Restock expected', $html);
    }

    public function testASaleWithoutLimitHasNoMaximum(): void
    {
        // Unlimited: the core publishes no availableQuantity at all, the parent says stock is not managed.
        $html = $this->render(['isPreorder' => true], stockManaged: false, remaining: \PHP_INT_MAX);

        self::assertStringContainsString('Pre-order', $html);
        self::assertDoesNotMatchRegularExpression('/<input[^>]*\bmax=/', $html);
    }

    public function testAMissingAvailableQuantityIsNoLimitEvenWhenTheParentSaysStockIsManaged(): void
    {
        $html = $this->render(['quantity' => 0], stockManaged: true, remaining: 0);

        self::assertStringNotContainsString('Out of stock', $html);
        self::assertDoesNotMatchRegularExpression('/<input[^>]*\bmax=/', $html);
    }

    public function testASaleModeThatRefusesAtZeroKeepsTheSoldOutSheet(): void
    {
        $html = $this->render(['quantity' => 0, 'availableQuantity' => 0.0, 'isPreorder' => false], remaining: 0);

        self::assertStringContainsString('Out of stock', $html);
        self::assertStringNotContainsString('Pre-order', $html);
        self::assertStringNotContainsString('Add to cart', $html);
    }

    public function testAnOrdinaryInStockSheetIsUnchanged(): void
    {
        $html = $this->render(['quantity' => 5, 'availableQuantity' => 5.0, 'isPreorder' => false], remaining: 5);

        self::assertStringContainsString('In stock', $html);
        self::assertStringNotContainsString('Pre-order', $html);
        self::assertMatchesRegularExpression('/<input[^>]*\bmax="5"/', $html);
    }

    /**
     * @param array<string, mixed> $pse
     */
    private function render(array $pse, bool $stockManaged = true, int $remaining = 0, string $locale = 'en'): string
    {
        /** @var FormFactoryInterface $forms */
        $forms = self::getContainer()->get('form.factory');
        $form = $forms->createNamedBuilder('cart_add', FormType::class, null, ['csrf_protection' => false]);

        foreach (['product', 'product_sale_elements_id', 'append', 'newness', 'quantity'] as $field) {
            $form->add($field, HiddenType::class);
        }

        self::getContainer()->get('twig')->addGlobal('lang_code', $locale);

        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender('Organisms:PseSelector:Base', [
            'form' => $form->getForm()->createView(),
            'currentPse' => $pse + ['id' => 7, 'isPromo' => false, 'untaxedPrice' => 10.0, 'price' => 12.0],
            'stockManaged' => $stockManaged,
            'remainingStock' => $remaining,
        ]);
    }
}
