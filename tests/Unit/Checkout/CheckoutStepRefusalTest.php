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

use FlexyBundle\Service\CheckoutStepRefusal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\Translation\Translator;
use Thelia\Domain\Checkout\Exception\CheckoutException;
use Thelia\Domain\Checkout\Exception\MissingAddressException;

/**
 * A step that refuses the order at placement sends the buyer back to the step still missing something, with its reason:
 * a step of the core as well as the step of a module, whose refusal the controller does not know by name.
 */
final class CheckoutStepRefusalTest extends TestCase
{
    protected function setUp(): void
    {
        // The exceptions of the core word their message through the shop's translator singleton.
        new Translator(new RequestStack());
    }

    public function testTheRefusalOfAModuleStepIsAnsweredWithTheStepAndItsReason(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $refusal = new class('Please enter a valid mobile phone number.') extends CheckoutException {
        };

        $redirect = (new CheckoutStepRefusal())->answer($refusal, '/checkout/phone', $session);

        self::assertSame('/checkout/phone', $redirect->getUrl());
        self::assertSame(302, $redirect->getStatusCode());
        self::assertSame(['Please enter a valid mobile phone number.'], $session->getFlashBag()->get('error'));
    }

    public function testACoreRefusalKeepsItsWording(): void
    {
        $session = new Session(new MockArraySessionStorage());

        (new CheckoutStepRefusal())->answer(new MissingAddressException('Delivery address is required'), '/checkout/delivery', $session);

        self::assertSame(['Delivery address is required'], $session->getFlashBag()->get('error'));
    }

    public function testWithoutASessionTheBuyerIsStillSentBack(): void
    {
        $redirect = (new CheckoutStepRefusal())->answer(new MissingAddressException('Delivery address is required'), '/checkout/delivery', null);

        self::assertSame('/checkout/delivery', $redirect->getUrl());
    }
}
