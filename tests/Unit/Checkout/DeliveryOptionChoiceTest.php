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

use FlexyBundle\Service\DeliveryOptionChoice;
use PHPUnit\Framework\TestCase;

/**
 * The delivery step used to write whatever module a live event named: a forged event put
 * an inactive module, or one that does not serve the address, on the cart.
 */
final class DeliveryOptionChoiceTest extends TestCase
{
    private const HOME = 7;

    private const PICKUP = 8;

    public function testAnOptionOfferedForTheCartIsTaken(): void
    {
        self::assertTrue(DeliveryOptionChoice::isOffered($this->offered(), 'home', self::HOME));
    }

    public function testAModuleTheCartIsNotOfferedIsRefused(): void
    {
        self::assertFalse(DeliveryOptionChoice::isOffered($this->offered(), 'home', 99));
    }

    public function testAnOptionCodeOfAnotherModuleIsRefused(): void
    {
        self::assertFalse(DeliveryOptionChoice::isOffered($this->offered(), 'pickup', self::HOME));
    }

    public function testNothingIsOfferedWithoutAnyOption(): void
    {
        self::assertFalse(DeliveryOptionChoice::isOffered([], 'home', self::HOME));
    }

    /**
     * Propel answers ids as int or numeric strings depending on the hydration.
     */
    public function testTheModuleIdIsReadWhateverShapeItComesIn(): void
    {
        self::assertTrue(DeliveryOptionChoice::isOffered(['home' => ['code' => 'home', 'moduleId' => '7']], 'home', self::HOME));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function offered(): array
    {
        return [
            'home' => ['code' => 'home', 'moduleId' => self::HOME],
            'pickup' => ['code' => 'pickup', 'moduleId' => self::PICKUP],
        ];
    }
}
