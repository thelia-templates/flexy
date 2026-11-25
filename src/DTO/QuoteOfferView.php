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

namespace FlexyBundle\DTO;

use Thelia\Domain\Quote\Enum\QuoteMode;

/**
 * What the shop offers a buyer about quotes for the cart in hand: whether the button that asks for
 * one is drawn, whether the direct way to the checkout still is, and whether the cart in hand is itself the
 * cart of an accepted quote, which the buyer may leave.
 */
final readonly class QuoteOfferView
{
    public function __construct(
        public QuoteMode $mode = QuoteMode::Disabled,
        public bool $canRequest = false,
        public bool $checkoutAllowed = true,
        public bool $inQuoteCart = false,
    ) {
    }
}
