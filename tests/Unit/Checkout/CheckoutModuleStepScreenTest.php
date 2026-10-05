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

use FlexyBundle\Service\CheckoutModuleStepScreen;
use PHPUnit\Framework\TestCase;
use Thelia\Domain\Checkout\DTO\CheckoutStepView;

/**
 * Which steps of the checkout this theme serves a screen for: the core ones, and a module's when it names the component
 * that draws it and its code can travel in a url.
 */
final class CheckoutModuleStepScreenTest extends TestCase
{
    private const CORE = ['cart', 'delivery', 'payment', 'confirmation'];

    public function testACoreStepHasItsOwnScreen(): void
    {
        self::assertTrue((new CheckoutModuleStepScreen())->hasScreen(self::step('delivery', null), self::CORE));
    }

    public function testAModuleStepNamingItsComponentHasAScreen(): void
    {
        self::assertTrue((new CheckoutModuleStepScreen())->hasScreen(self::step('loyalty_redeem', 'Loyalty:Redeem'), self::CORE));
    }

    public function testAModuleStepWithoutComponentHasNoScreen(): void
    {
        $screen = new CheckoutModuleStepScreen();

        self::assertFalse($screen->hasScreen(self::step('loyalty_redeem', null), self::CORE));
        self::assertFalse($screen->hasScreen(self::step('loyalty_redeem', ''), self::CORE));
    }

    public function testACodeThatCannotTravelInAUrlHasNoScreen(): void
    {
        $screen = new CheckoutModuleStepScreen();

        self::assertFalse($screen->hasScreen(self::step('Loyalty/Redeem', 'Loyalty:Redeem'), self::CORE));
        self::assertFalse($screen->isServable(''));
        self::assertTrue($screen->isServable('gift_message2'));
    }

    public function testTheNavigationKeepsTheStepsWithAScreenInOrder(): void
    {
        $steps = [self::step('cart', null), self::step('delivery', null), self::step('gift', null), self::step('loyalty', 'Loyalty:Redeem'), self::step('payment', null), self::step('confirmation', null)];

        self::assertSame(['cart', 'delivery', 'loyalty', 'payment', 'confirmation'], (new CheckoutModuleStepScreen())->navigableCodes($steps, self::CORE));
    }

    public function testAFirstIncompleteStepWithoutScreenLandsOnTheLastStepBeforeItThatHasOne(): void
    {
        $steps = [self::step('cart', null), self::step('delivery', null), self::step('gift', null), self::step('payment', null)];

        // No redirect loop: the buyer is not sent to "gift" (no screen), and "delivery" is a screen of its own.
        self::assertSame('delivery', (new CheckoutModuleStepScreen())->landingFor('gift', $steps, self::CORE, 'cart'));
    }

    public function testAStepWithoutScreenAheadOfEveryOtherLandsOnTheFallback(): void
    {
        $steps = [self::step('gift', null), self::step('cart', null)];

        self::assertSame('cart', (new CheckoutModuleStepScreen())->landingFor('gift', $steps, self::CORE, 'cart'));
        self::assertSame('cart', (new CheckoutModuleStepScreen())->landingFor('unknown', $steps, self::CORE, 'cart'));
    }

    public function testAStepWithAScreenLandsOnItself(): void
    {
        $steps = [self::step('cart', null), self::step('loyalty', 'Loyalty:Redeem')];

        self::assertSame('loyalty', (new CheckoutModuleStepScreen())->landingFor('loyalty', $steps, self::CORE, 'cart'));
    }

    public function testTheRouteOfAStepIsItsCoreRouteTheSharedRouteOrTheEntryRoute(): void
    {
        $screen = new CheckoutModuleStepScreen();
        $routes = ['cart' => 'checkout_cart', 'delivery' => 'checkout_delivery'];

        self::assertSame(['checkout_delivery', []], $screen->locate('delivery', $routes, 'checkout_cart'));
        self::assertSame(['checkout_step', ['code' => 'loyalty_redeem']], $screen->locate('loyalty_redeem', $routes, 'checkout_cart'));
        self::assertSame(['checkout_cart', []], $screen->locate('Not/Servable', $routes, 'checkout_cart'));
    }

    private static function step(string $code, ?string $component): CheckoutStepView
    {
        return new CheckoutStepView($code, $code, 1, false, $component);
    }
}
