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

namespace FlexyBundle\Tests\Integration;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Catalog\Quantity\QuantityRule;
use Thelia\Domain\Catalog\Quantity\UnitCode;
use Thelia\Model\ProductSaleElements;
use Thelia\Test\IntegrationTestCase;

/**
 * Each row of the quick order table types its quantity in the selector of the product page, on
 * the rule of the sale element the check settled it on: a number field in whole steps for an item
 * sold by the piece, a decimal field with its unit for one sold by measure. A row not checked yet
 * takes a decimal field. Runs on an installed shop whose core has quantity rules.
 */
final class QuickOrderTableQuantitySelectorTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        if (!class_exists(QuantityRule::class)) {
            self::markTestSkipped('This core has no quantity rules.');
        }

        parent::setUp();
    }

    public function testAnItemSoldByThePieceTakesWholeSteps(): void
    {
        $bySix = $this->saleElement(new QuantityRule(minQuantity: 6.0, step: 6.0));

        $input = $this->quantityInput($this->render($bySix, 12, 'en_US'));

        self::assertStringContainsString('type="number"', $input);
        self::assertStringContainsString('step="6"', $input);
        self::assertStringContainsString('min="6"', $input);
        self::assertStringContainsString('value="12"', $input);
        self::assertStringContainsString('data-model="norender|on(change)|rows.0.quantity"', $input);
    }

    public function testAnItemSoldByMeasureTakesADecimalInTheLanguageOfThePageAndItsUnit(): void
    {
        $byTheQuarter = $this->saleElement(new QuantityRule(minQuantity: 0.5, step: 0.25, decimals: 2, unitCode: UnitCode::KILOGRAM));

        $html = $this->render($byTheQuarter, 1.25, 'fr_FR');
        $input = $this->quantityInput($html);

        self::assertStringContainsString('type="text" inputmode="decimal"', $input);
        self::assertStringContainsString('value="1,25"', $input);
        self::assertStringContainsString('ButtonQuantity__unit">kg</span>', $html);
        self::assertStringContainsString('data-Molecules--Button--quantity-step-value="0.25"', $html);
        self::assertStringContainsString('data-Molecules--Button--quantity-decimals-value="2"', $html);
    }

    public function testARowNotCheckedYetTakesADecimalField(): void
    {
        $html = $this->renderTemplate('<twig:Organisms:QuickOrderTable:Base :rows="[{key: \'k1\', reference: \'flour\', quantity: \'1,5\'}]" />', 'en_US');
        $input = $this->quantityInput($html);

        self::assertStringContainsString('type="text" inputmode="decimal"', $input);
        self::assertStringContainsString('value="1,5"', $input, 'what the buyer typed, as typed');
        self::assertStringNotContainsString('ButtonQuantity__unit', $html);
    }

    private function saleElement(QuantityRule $rule): ProductSaleElements
    {
        $factory = $this->createFixtureFactory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['ref' => 'QO-'.uniqid()]);
        $saleElement = $product->getProductSaleElementss()->getFirst();
        \assert($saleElement instanceof ProductSaleElements);
        $rule->applyTo($saleElement);
        $saleElement->save();

        return $saleElement;
    }

    private function render(ProductSaleElements $saleElement, float $quantity, string $locale): string
    {
        $id = (int) $saleElement->getId();
        $ref = (string) $saleElement->getRef();

        return $this->renderTemplate(\sprintf(
            '<twig:Organisms:QuickOrderTable:Base :rows="[{key: \'k1\', reference: \'%1$s\', quantity: %2$s, productSaleElementsId: %3$d}]" :lines="[{reference: \'%1$s\', quantity: %2$s, status: \'resolved\', title: \'Item\', productSaleElementsId: %3$d, candidates: []}]" />',
            $ref,
            var_export($quantity, true),
            $id,
        ), $locale);
    }

    private function renderTemplate(string $template, string $locale): string
    {
        $request = Request::create('/account/quick-order');
        $request->setLocale($locale);
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);
        self::getContainer()->get('twig')->addGlobal('lang_code', substr($locale, 0, 2));

        try {
            return self::getContainer()->get('twig')->createTemplate($template)->render();
        } finally {
            self::getContainer()->get('request_stack')->pop();
        }
    }

    private function quantityInput(string $html): string
    {
        // The tag as a whole: its attributes hold "->", which a [^>]* would stop at.
        self::assertSame(1, preg_match('/<input\s(?:"[^"]*"|[^>"])*id="quick-order-quantity-k1"(?:"[^"]*"|[^>"])*>/', $html, $input), 'the quantity field of the row');

        return $input[0];
    }
}
