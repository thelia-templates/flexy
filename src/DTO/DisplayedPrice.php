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

use FlexyBundle\Service\TaxDisplay;

/**
 * An amount ready to be printed, with the tax display it was chosen under: the template
 * writes the figure and hands the display to the mention component, and never decides
 * which of the two figures is the right one.
 */
final readonly class DisplayedPrice
{
    public function __construct(
        public float $amount,
        public TaxDisplay $display,
    ) {
    }
}
