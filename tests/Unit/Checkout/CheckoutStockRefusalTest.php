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

namespace FlexyBundle\Tests\Unit\Checkout;

use FlexyBundle\Service\CheckoutStockRefusal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Translation\IdentityTranslator;
use Thelia\Core\Translation\Translator;
use Thelia\Domain\Order\Exception\StockShortageException;

/**
 * The stock lost while the order is placed is answered with the cart and a sentence in the buyer's language, not with
 * the wording of the exception (written for a log).
 */
final class CheckoutStockRefusalTest extends TestCase
{
    protected function setUp(): void
    {
        // The exception words its own message through the shop's translator singleton.
        new Translator(new RequestStack());
    }

    public function testTheBuyerIsSentToTheCartWithTheProductNamed(): void
    {
        $session = new Session(new MockArraySessionStorage());

        $redirect = (new CheckoutStockRefusal(new IdentityTranslator()))->answer(new StockShortageException('REF-1'), '/checkout/cart', $session);

        self::assertSame('/checkout/cart', $redirect->getUrl());
        self::assertSame(302, $redirect->getStatusCode());
        self::assertSame(['The stock of REF-1 ran out while your order was placed. Please adjust your cart.'], $session->getFlashBag()->get('error'));
    }

    public function testWithoutAReferenceTheGenericSentenceIsUsed(): void
    {
        $session = new Session(new MockArraySessionStorage());

        (new CheckoutStockRefusal(new IdentityTranslator()))->answer(new StockShortageException(), '/checkout/cart', $session);

        self::assertSame(['Some products in your cart are no longer available in the requested quantity. Please adjust the quantities before ordering.'], $session->getFlashBag()->get('error'));
    }

    public function testTheRawWordingOfTheExceptionIsNeverShown(): void
    {
        $session = new Session(new MockArraySessionStorage());

        $redirect = (new CheckoutStockRefusal(new IdentityTranslator()))->answer(new StockShortageException('REF-1'), '/checkout/cart', $session);

        self::assertStringNotContainsString('Not enough stock', (string) $redirect->getMessage());
        self::assertStringNotContainsString('Not enough stock', implode('', $session->getFlashBag()->get('error')));
    }
}
