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

use FlexyBundle\Service\BillingAddressChoice;
use PHPUnit\Framework\TestCase;

/**
 * The billing address the payment step opens on.
 *
 * Nothing on that step used to choose one: the cart kept `address_invoice_id` at null
 * until the buyer clicked an address card, the core refuses an order without a billing
 * address, and the Order button stayed grey the whole time with nothing saying why.
 */
final class BillingAddressChoiceTest extends TestCase
{
    private const SHIPS_TO = 11;

    private const DEFAULT_ADDRESS = 22;

    private const OTHER_ADDRESS = 33;

    public function testTheBuyerIsBilledWhereTheOrderShips(): void
    {
        self::assertSame(self::SHIPS_TO, BillingAddressChoice::from($this->addressBook(), self::SHIPS_TO));
    }

    /**
     * A cart with nothing to ship never goes through a delivery step, so there is no
     * address to copy: the one the account calls its default is the next best answer.
     */
    public function testWithNothingToShipTheDefaultAddressIsChosen(): void
    {
        self::assertSame(self::DEFAULT_ADDRESS, BillingAddressChoice::from($this->addressBook(), null));
    }

    /**
     * A guest sees only the addresses of their own identification, and the shared customer
     * row may name one written by an earlier buyer. Such an address is not this checkout's
     * to bill, and the fallback takes over.
     */
    public function testAnAddressTheCheckoutCannotSeeIsNotBilledTo(): void
    {
        self::assertSame(self::DEFAULT_ADDRESS, BillingAddressChoice::from($this->addressBook(), 999));
    }

    public function testWithNoDefaultAddressTheFirstOfTheBookIsChosen(): void
    {
        $book = [
            ['id' => self::OTHER_ADDRESS, 'isDefault' => false],
            ['id' => self::SHIPS_TO, 'isDefault' => false],
        ];

        self::assertSame(self::OTHER_ADDRESS, BillingAddressChoice::from($book, null));
    }

    public function testAnEmptyAddressBookAnswersNothing(): void
    {
        self::assertNull(BillingAddressChoice::from([], self::SHIPS_TO));
    }

    /**
     * The gate filters the book of a guest and hands back what is left: the keys of the
     * addresses that survived are not 0..n, and the fallback must still answer the first
     * of them rather than trip on a missing index.
     */
    public function testABookWhoseKeysWereFilteredStillAnswersItsFirstAddress(): void
    {
        $book = [
            3 => ['id' => self::OTHER_ADDRESS, 'isDefault' => false],
            7 => ['id' => self::SHIPS_TO, 'isDefault' => false],
        ];

        self::assertSame(self::OTHER_ADDRESS, BillingAddressChoice::from($book, null));
    }

    /**
     * Propel and the API both answer a boolean column as 0/1 often enough that the default
     * flag must not be read with a strict comparison.
     */
    public function testTheDefaultFlagIsReadWhateverShapeItComesIn(): void
    {
        $book = [
            ['id' => self::OTHER_ADDRESS, 'isDefault' => 0],
            ['id' => self::DEFAULT_ADDRESS, 'isDefault' => 1],
        ];

        self::assertSame(self::DEFAULT_ADDRESS, BillingAddressChoice::from($book, null));
    }

    /**
     * @return list<array{id: int, isDefault: bool}>
     */
    private function addressBook(): array
    {
        return [
            ['id' => self::OTHER_ADDRESS, 'isDefault' => false],
            ['id' => self::DEFAULT_ADDRESS, 'isDefault' => true],
            ['id' => self::SHIPS_TO, 'isDefault' => false],
        ];
    }
}
