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

use FlexyBundle\Service\PaymentOptionChoice;
use PHPUnit\Framework\TestCase;

final class PaymentOptionChoiceTest extends TestCase
{
    public function testAnOfferedOptionIsPicked(): void
    {
        self::assertSame(
            [['group' => 'instalments', 'values' => ['three']]],
            PaymentOptionChoice::answer($this->offered(), [], 'instalments', 'three', true),
        );
    }

    public function testAnOptionTheModuleDoesNotOfferIsRefused(): void
    {
        self::assertSame([], PaymentOptionChoice::answer($this->offered(), [], 'instalments', 'twelve', true));
    }

    public function testAnOptionOfAGroupTheModuleDoesNotOfferIsRefused(): void
    {
        self::assertSame([], PaymentOptionChoice::answer($this->offered(), [], 'loyalty', 'three', true));
    }

    public function testAnOptionTheModuleMarksInvalidIsRefused(): void
    {
        self::assertSame([], PaymentOptionChoice::answer($this->offered(), [], 'instalments', 'four', true));
    }

    public function testAGroupOfOneOptionSwapsThePickedOne(): void
    {
        $choices = [['group' => 'instalments', 'values' => ['three']]];

        self::assertSame(
            [['group' => 'instalments', 'values' => ['two']]],
            PaymentOptionChoice::answer($this->offered(), $choices, 'instalments', 'two', true),
        );
    }

    public function testAGroupNeverHoldsMoreOptionsThanItAllows(): void
    {
        $choices = [['group' => 'extras', 'values' => ['insurance', 'receipt']]];

        self::assertSame(
            $choices,
            PaymentOptionChoice::answer($this->offered(), $choices, 'extras', 'gift', true),
        );
    }

    public function testAnOptionLetGoLeavesTheGroupWhenItWasTheLastOne(): void
    {
        $choices = [
            ['group' => 'instalments', 'values' => ['three']],
            ['group' => 'extras', 'values' => ['receipt']],
        ];

        self::assertSame(
            [['group' => 'instalments', 'values' => ['three']]],
            PaymentOptionChoice::answer($this->offered(), $choices, 'extras', 'receipt', false),
        );
    }

    public function testTheSameAnswerTwiceChangesNothing(): void
    {
        $once = PaymentOptionChoice::answer($this->offered(), [], 'extras', 'receipt', true);

        self::assertSame($once, PaymentOptionChoice::answer($this->offered(), $once, 'extras', 'receipt', true));
    }

    public function testChoicesNoLongerOfferedAreDropped(): void
    {
        $choices = [
            ['group' => 'instalments', 'values' => ['four']],
            ['group' => 'loyalty', 'values' => ['points']],
        ];

        self::assertSame(
            [['group' => 'extras', 'values' => ['receipt']]],
            PaymentOptionChoice::answer($this->offered(), $choices, 'extras', 'receipt', true),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function offered(): array
    {
        return [
            [
                'code' => 'instalments',
                'minimumSelectedOptions' => 1,
                'maximumSelectedOptions' => 1,
                'options' => [
                    ['code' => 'two', 'valid' => true],
                    ['code' => 'three', 'valid' => true],
                    ['code' => 'four', 'valid' => false],
                ],
            ],
            [
                'code' => 'extras',
                'minimumSelectedOptions' => 0,
                'maximumSelectedOptions' => 2,
                'options' => [
                    ['code' => 'insurance', 'valid' => true],
                    ['code' => 'receipt', 'valid' => true],
                    ['code' => 'gift', 'valid' => true],
                ],
            ],
        ];
    }
}
