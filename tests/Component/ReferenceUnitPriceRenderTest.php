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
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Catalog\Quantity\ReferenceUnitPriceCalculator;

/**
 * The sheet shows the price per litre of a bottle of 75 cl under the price it shows, promotion
 * included; a sale element that says nothing of its units shows none. The card is covered by
 * Integration\ProductCardReferencePriceTest: it prices a product that exists.
 */
final class ReferenceUnitPriceRenderTest extends KernelTestCase
{
    protected function setUp(): void
    {
        if (!class_exists(ReferenceUnitPriceCalculator::class)) {
            self::markTestSkipped('This core has no reference unit prices.');
        }

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $request = Request::create('https://localhost/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');
    }

    public function testTheSheetShowsThePricePerLitreOfThePriceItShows(): void
    {
        $bottle = QuantityRuleDTO::fromArray(['unitQuantity' => 0.75, 'referenceUnitQuantity' => 1, 'unitCode' => 'l']);

        $price = $this->sheet($bottle, ['isPromo' => false, 'price' => 9.99, 'promoPrice' => 9.99])->filter('.PseSelector-referencePrice');
        self::assertCount(1, $price);
        self::assertSame('€13.32 / l', trim($price->text()));

        $promo = $this->sheet($bottle, ['isPromo' => true, 'price' => 9.99, 'promoPrice' => 7.5])->filter('.PseSelector-referencePrice');
        self::assertSame('€10.00 / l', trim($promo->text()), 'of the promotion price, the one shown');
    }

    public function testASheetWithoutUnitsShowsNoReferencePrice(): void
    {
        self::assertCount(0, $this->sheet(new QuantityRuleDTO(), ['isPromo' => false, 'price' => 9.99, 'promoPrice' => 9.99])->filter('.PseSelector-referencePrice'));
    }

    /**
     * @param array<string, mixed> $pse
     */
    private function sheet(QuantityRuleDTO $rule, array $pse): Crawler
    {
        $builder = self::getContainer()->get(FormFactoryInterface::class)
            ->createNamedBuilder('thelia_cart_add', FormType::class, ['quantity' => '1'], ['csrf_protection' => false]);

        foreach (['product', 'product_sale_elements_id', 'quantity', 'append', 'newness'] as $field) {
            $builder->add($field, TextType::class);
        }

        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return new Crawler($renderer->createAndRender('Organisms:PseSelector:Base', [
            'form' => $builder->getForm()->createView(),
            'currentPse' => ['id' => 7, 'quantity' => 20, ...$pse],
            'stockManaged' => true,
            'remainingStock' => 20.0,
            'quantityRule' => $rule,
        ]));
    }
}
