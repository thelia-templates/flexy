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

use Thelia\Domain\Cart\Service\CartGuard;
use Thelia\Domain\Checkout\Exception\CartQuantitiesAdjustedException;
use Thelia\Model\Cart;

/**
 * Brings the lines of a cart read again (a cart restored from its cookie, a combination whose
 * rules changed since) to the rules of their combination, the moment a page shows them.
 *
 * The core adjusts such a line when it restores the cart of a customer, and at each guarded
 * step of the checkout; a visitor nobody knows reaches the cart page and the identification
 * page without either. Each adjusted line is told to the shopper by the flash the core's
 * adjustment event leaves (QuantityAdjustedFlashListener), read by the page
 * (Organisms/QuantityAdjustments): the refusal the guard throws to stop a checkout step is
 * of no use on a page that only shows the cart.
 *
 * A core without quantity rules has nothing to adjust.
 */
final readonly class RestoredCartLines
{
    public function __construct(private CartGuard $cartGuard)
    {
    }

    public function adjust(?Cart $cart): void
    {
        if (null === $cart || !method_exists($this->cartGuard, 'checkQuantities')) {
            return;
        }

        try {
            $this->cartGuard->checkQuantities($cart);
        } catch (CartQuantitiesAdjustedException) {
            // Adjusted and told: the page shows the lines as they now are.
        }
    }
}
