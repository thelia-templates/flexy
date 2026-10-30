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

use FlexyBundle\EventListener\QuantityAdjustedFlashListener;
use FlexyBundle\Service\RestoredCartLines;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Cart\Service\CartGuard;
use Thelia\Domain\Catalog\Quantity\QuantityRule;
use Thelia\Model\CartItemQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Test\IntegrationTestCase;

/**
 * The cart page and the identification page bring a line read again to the rules of its
 * combination: seven of a lot of six becomes six, and the shopper is told by the flash the
 * page shows. A line that meets its rules is left alone and nothing is told.
 * Runs on an installed shop whose core has quantity rules.
 */
final class RestoredCartLinesTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        if (!class_exists(QuantityRule::class) || !method_exists(CartGuard::class, 'checkQuantities')) {
            self::markTestSkipped('This core has no quantity rules.');
        }

        parent::setUp();
    }

    public function testALineOffTheStepIsBroughtDownAndTheShopperIsTold(): void
    {
        [$cart, $line] = $this->cartWithALotOfSix(7.0);

        $this->restoredCartLines()->adjust($cart);

        self::assertSame(6.0, (float) CartItemQuery::create()->findPk($line)?->getQuantity());
        $told = $this->session()->getFlashBag()->get(QuantityAdjustedFlashListener::FLASH_KEY);
        self::assertCount(1, $told);
        self::assertSame('Victoria', $told[0]['product']);
        self::assertNotSame('', $told[0]['message']);
    }

    public function testALineThatMeetsItsRulesIsLeftAlone(): void
    {
        [$cart, $line] = $this->cartWithALotOfSix(12.0);

        $this->restoredCartLines()->adjust($cart);

        self::assertSame(12.0, (float) CartItemQuery::create()->findPk($line)?->getQuantity());
        self::assertSame([], $this->session()->getFlashBag()->get(QuantityAdjustedFlashListener::FLASH_KEY));
    }

    public function testNoCartIsNothingToAdjust(): void
    {
        $this->restoredCartLines()->adjust(null);

        self::assertSame([], $this->session()->getFlashBag()->get(QuantityAdjustedFlashListener::FLASH_KEY));
    }

    /**
     * @return array{\Thelia\Model\Cart, int}
     */
    private function cartWithALotOfSix(float $quantity): array
    {
        $factory = $this->createFixtureFactory();
        $currency = $factory->currency();
        $product = $factory->product($factory->category(), $factory->taxRule(), $currency, ['title' => 'Victoria', 'locale' => 'en_US', 'basePrice' => 10.0, 'baseQuantity' => 100]);
        $saleElement = $product->getProductSaleElementss()->getFirst();
        \assert($saleElement instanceof ProductSaleElements);
        // The line went in before the rule: the rule is the one the shop has since.
        $cart = $factory->cart(null, ['currency' => $currency]);
        $line = $factory->cartItem($cart, $product, $saleElement, ['quantity' => $quantity]);
        (new QuantityRule(minQuantity: 2.0, step: 6.0))->applyTo($saleElement);
        $saleElement->save();

        return [$cart, (int) $line->getId()];
    }

    private function restoredCartLines(): RestoredCartLines
    {
        return new RestoredCartLines($this->getService(CartGuard::class));
    }

    private function session(): Session
    {
        $stack = static::getContainer()->get('request_stack');
        \assert($stack instanceof RequestStack);
        $session = $stack->getMainRequest()?->getSession();
        \assert($session instanceof Session);

        return $session;
    }
}
