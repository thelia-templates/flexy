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

namespace FlexyBundle\Service;

/**
 * Which of the two amounts the core publishes for a price the theme shows: the one with the
 * taxes in it, or the one without. Decided by the customer group of the buyer, and frozen on
 * an order once it is placed.
 */
enum TaxDisplay: string
{
    case Taxed = 'taxed';
    case Untaxed = 'untaxed';

    public function isTaxed(): bool
    {
        return self::Taxed === $this;
    }

    public static function fromTaxed(bool $taxed): self
    {
        return $taxed ? self::Taxed : self::Untaxed;
    }

    /**
     * The translation key of the mention printed beside the amount.
     */
    public function mention(): string
    {
        return $this->isTaxed() ? 'incl. tax' : 'excl. tax';
    }
}
