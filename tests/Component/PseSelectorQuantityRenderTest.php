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

use FlexyBundle\DTO\QuantityRuleDTO;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Domain\Catalog\Quantity\UnitCode;

/**
 * The selector offers only the quantities the sale element is sold in: a lot of six is a list of
 * its multiples, a weight is a field that takes decimals and shows its unit, a sale element sold
 * by the piece keeps its stepper from one. The sentence of the cart refusing a quantity (below
 * the minimum, off the step) is shown as it is, as text.
 */
final class PseSelectorQuantityRenderTest extends KernelTestCase
{
    public function testALotIsAListOfItsMultiples(): void
    {
        $crawler = $this->render(
            ['quantityRule' => QuantityRuleDTO::fromArray(['minQuantity' => 2, 'quantityStep' => 6]), 'quantityChoices' => [
                ['value' => '6', 'label' => '6'], ['value' => '12', 'label' => '12'], ['value' => '18', 'label' => '18'],
            ]],
            quantity: '12',
        );

        $select = $crawler->filter('select[name="thelia_cart_add[quantity]"]');
        self::assertCount(1, $select);
        self::assertSame(['6', '12', '18'], $select->filter('option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame('12', $select->filter('option[selected]')->attr('value'));
        self::assertCount(0, $crawler->filter('input[name="thelia_cart_add[quantity]"]'), 'no field to type a seven in');
    }

    public function testAWeightIsTypedWithItsDecimalsFromItsMinimumAndShowsItsUnit(): void
    {
        $crawler = $this->render(['quantityRule' => QuantityRuleDTO::fromArray(['minQuantity' => 0.5, 'quantityStep' => 0.25, 'quantityDecimals' => 2, 'unitCode' => 'kg'])], quantity: '0.50');

        $input = $crawler->filter('input[name="thelia_cart_add[quantity]"]');
        self::assertSame('text', $input->attr('type'));
        self::assertSame('decimal', $input->attr('inputmode'));
        self::assertSame('0.50', $input->attr('value'));
        $stepper = $crawler->filter('.ButtonQuantity');
        self::assertSame('0.25', $stepper->attr('data-molecules--button--quantity-step-value'));
        self::assertSame('0.50', $stepper->attr('data-molecules--button--quantity-min-value'));
        self::assertSame('2', $stepper->attr('data-molecules--button--quantity-decimals-value'));
        // The symbol is the core's (UnitCode): a core without quantity rules has none to show.
        $unit = $crawler->filter('.ButtonQuantity__unit');
        self::assertSame(enum_exists(UnitCode::class) ? 'kg' : '', $unit->count() > 0 ? $unit->text() : '');
    }

    public function testAWeightShowsTheQuantityTheComponentWroteWithTheDecimalsOfTheRule(): void
    {
        $input = $this->render(['quantityRule' => QuantityRuleDTO::fromArray(['minQuantity' => 0.5, 'quantityStep' => 0.25, 'quantityDecimals' => 2]), 'quantityValue' => '0,50'], quantity: '0,5')
            ->filter('input[name="thelia_cart_add[quantity]"]');

        self::assertSame('0,50', $input->attr('value'), 'written as the stepper writes it after a press, not as the form did');
    }

    public function testTheAddToCartOfAWeightGoesUnderTheField(): void
    {
        $weight = $this->render(['quantityRule' => QuantityRuleDTO::fromArray(['minQuantity' => 0.5, 'quantityStep' => 0.25, 'quantityDecimals' => 2, 'unitCode' => 'kg'])], quantity: '0.50');
        $piece = $this->render([], quantity: '1');
        $lot = $this->render(['quantityRule' => QuantityRuleDTO::fromArray(['quantityStep' => 6]), 'quantityChoices' => [['value' => '6', 'label' => '6']]], quantity: '6');

        self::assertCount(1, $weight->filter('.PseSelector-addToCart.PseSelector-addToCart--stacked'));
        self::assertCount(0, $piece->filter('.PseSelector-addToCart--stacked'), 'a sale element sold by the piece keeps the merged pill');
        self::assertCount(0, $lot->filter('.PseSelector-addToCart--stacked'), 'and so does a lot');
    }

    public function testTheHighestQuantityOfTheStepperIsTheLastOneTheStockLeftCanFill(): void
    {
        $crawler = $this->render(['quantityRule' => QuantityRuleDTO::fromArray(['quantityStep' => 0.25, 'quantityDecimals' => 2]), 'remainingStock' => 7.6], quantity: '1.00');

        self::assertSame('7.50', $crawler->filter('.ButtonQuantity')->attr('data-molecules--button--quantity-max-value'));
    }

    public function testASaleElementSoldByThePieceKeepsItsStepperFromOne(): void
    {
        $input = $this->render([], quantity: '1')->filter('input[name="thelia_cart_add[quantity]"]');

        self::assertSame('number', $input->attr('type'));
        self::assertSame('1', $input->attr('min'));
        self::assertSame('1', $input->attr('step'));
        self::assertSame('20', $input->attr('max'));
    }

    public function testAShortageOfStockForTheSmallestLotIsNotSaidToBeTheMaximumInTheCart(): void
    {
        $lot = QuantityRuleDTO::fromArray(['quantityStep' => 6]);

        $shortage = $this->render(['quantityRule' => $lot, 'maxQuantityReached' => true, 'stockBelowSmallestQuantity' => true], quantity: '6')->filter('.SnackBar')->text();
        $reached = $this->render(['quantityRule' => $lot, 'maxQuantityReached' => true], quantity: '6')->filter('.SnackBar')->text();

        self::assertStringContainsString('Not enough stock for the smallest quantity', $shortage);
        self::assertStringNotContainsString('maximum available quantity', $shortage);
        self::assertStringContainsString('maximum available quantity', $reached);
    }

    public function testTheSentenceOfTheCartRefusingAQuantityIsShownAsText(): void
    {
        $alert = $this->render(['quantityRule' => QuantityRuleDTO::fromArray(['quantityStep' => 6])], quantity: '7', error: 'Sold <b>by 6</b>: 6, 12, 18…')
            ->filter('[role="alert"]');

        self::assertCount(1, $alert);
        self::assertStringContainsString('Sold <b>by 6</b>: 6, 12, 18…', $alert->text());
        self::assertCount(0, $alert->filter('b'));
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props, string $quantity, ?string $error = null): Crawler
    {
        $builder = self::getContainer()->get(FormFactoryInterface::class)
            ->createNamedBuilder('thelia_cart_add', FormType::class, ['quantity' => $quantity], ['csrf_protection' => false]);

        foreach (['product', 'product_sale_elements_id', 'quantity', 'append', 'newness'] as $field) {
            $builder->add($field, TextType::class);
        }

        $form = $builder->getForm();

        if (null !== $error) {
            $form->get('quantity')->addError(new FormError($error));
        }

        // The price below the selector reads the language Thelia's parser publishes for a page.
        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');

        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return new Crawler($renderer->createAndRender('Organisms:PseSelector:Base', [
            'form' => $form->createView(),
            'currentPse' => ['id' => 7, 'quantity' => 20, 'isPromo' => false, 'price' => 10.0, 'promoPrice' => 10.0],
            'stockManaged' => true,
            'remainingStock' => 20.0,
            ...$props,
        ]));
    }
}
