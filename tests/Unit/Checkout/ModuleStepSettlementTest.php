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

use FlexyBundle\Service\ModuleStepSettlement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Translation\Translator;
use Thelia\Domain\Checkout\Exception\MissingAddressException;
use Thelia\Domain\Checkout\Service\Step\CheckoutStepProviderInterface;
use Thelia\Model\Cart;

/**
 * The next button let the buyer through any step a module declared (`default => true`): a billing or a phone step was
 * "settled" before the buyer had done anything on it. Such a step is now asked the check it is held to at the placement.
 */
final class ModuleStepSettlementTest extends TestCase
{
    protected function setUp(): void
    {
        // The refusals of the core translate their message, and read the translator through its singleton.
        new Translator(new RequestStack());
    }

    public function testAStepWhoseCheckPassesIsSettled(): void
    {
        self::assertTrue(ModuleStepSettlement::isSettled([$this->provider('phone', false)], new Cart(), 'phone'));
    }

    public function testAStepWhoseCheckRefusesTheCartIsNotSettled(): void
    {
        self::assertFalse(ModuleStepSettlement::isSettled([$this->provider('phone', true)], new Cart(), 'phone'));
    }

    public function testOnlyTheProviderOfTheStepIsAsked(): void
    {
        self::assertTrue(ModuleStepSettlement::isSettled([$this->provider('billing', true), $this->provider('phone', false)], new Cart(), 'phone'));
    }

    public function testAStepNoProviderDeclaresIsLeftToThePlacement(): void
    {
        self::assertTrue(ModuleStepSettlement::isSettled([$this->provider('billing', true)], new Cart(), 'unknown'));
    }

    private function provider(string $code, bool $refuses): CheckoutStepProviderInterface
    {
        return new class($code, $refuses) implements CheckoutStepProviderInterface {
            public function __construct(private readonly string $code, private readonly bool $refuses)
            {
            }

            public function code(): string
            {
                return $this->code;
            }

            public function defaultPosition(): int
            {
                return 3;
            }

            public function isMandatory(): bool
            {
                return true;
            }

            public function isSkippedFor(Cart $cart): bool
            {
                return false;
            }

            public function check(Cart $cart): void
            {
                if ($this->refuses) {
                    throw new MissingAddressException();
                }
            }

            public function componentName(): ?string
            {
                return null;
            }
        };
    }
}
