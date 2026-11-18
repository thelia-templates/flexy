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

use Thelia\Core\Security\SecurityContext;
use Thelia\Model\ConfigQuery;

/**
 * Whether the shop shows its prices to the visitor, and so lets them buy.
 *
 * Read from the configuration variable the core 3.4 names for it, `price_display_mode`:
 * `visible` (every visitor), `logged_in` (a customer who signed in) or `on_request` (nobody,
 * which is what a showcase site that sells nothing sets). A core 3.3 does not know the
 * variable: unset, or holding a value this theme does not know, it reads as visible, so that
 * no shop loses its prices by a typo. Only the shop-wide setting is read here; a product of
 * its own mode is a matter of the core 3.4 and its front API.
 *
 * A visitor who is not shown the prices is offered nothing that leads to a purchase either:
 * no add to cart, no cart, no currency to choose.
 */
final readonly class ShopPriceDisplay
{
    public const string CONFIG_KEY = 'price_display_mode';

    public function __construct(
        private SecurityContext $securityContext,
    ) {
    }

    public function showsPrices(): bool
    {
        return match ((string) ConfigQuery::read(self::CONFIG_KEY, 'visible')) {
            'on_request' => false,
            'logged_in' => $this->securityContext->hasAuthenticatedCustomerUser(),
            default => true,
        };
    }
}
