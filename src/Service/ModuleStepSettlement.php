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

use Thelia\Domain\Checkout\Exception\CheckoutException;
use Thelia\Domain\Checkout\Service\Step\CheckoutStepProviderInterface;
use Thelia\Model\Cart;

/**
 * Whether a step a module declared is settled for a cart: the answer of its provider's check(), the one the placement
 * holds the cart to. A step no provider declares any more is left to the placement.
 *
 * A decision and nothing else, so that it can be read and tested on its own.
 */
final readonly class ModuleStepSettlement
{
    /**
     * @param iterable<CheckoutStepProviderInterface> $providers
     */
    public static function isSettled(iterable $providers, Cart $cart, string $code): bool
    {
        foreach ($providers as $provider) {
            if ($provider->code() !== $code) {
                continue;
            }

            try {
                $provider->check($cart);
            } catch (CheckoutException) {
                return false;
            }

            return true;
        }

        return true;
    }
}
