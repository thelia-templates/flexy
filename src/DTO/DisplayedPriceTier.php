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

/**
 * One tier of a price list as the buyer reads it: from which quantity it applies, and its unit
 * price in the display of the buyer, ready to be printed.
 */
final readonly class DisplayedPriceTier
{
    public function __construct(
        public int $minQuantity,
        public DisplayedPrice $price,
    ) {
    }
}
