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

use Thelia\Domain\Quote\Cart\QuoteCartDeliveryGuard;
use Thelia\Domain\Quote\Cart\QuoteCartInspector;
use Thelia\Model\Cart;
use Thelia\Model\CartAddress;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Module\BaseModule;

/**
 * How the cart of a quote ships, as the delivery step shows it.
 *
 * The carrier and the address the quote names are imposed: shown, never offered. What the quote leaves open
 * is offered once, and what the buyer chose is then held on the cart like what the quote names, which is
 * the rule the core refuses a change against.
 */
final readonly class QuoteDeliveryTerms
{
    public function __construct(
        private QuoteCartInspector $inspector,
        private QuoteCartDeliveryGuard $guard,
    ) {
    }

    public function isQuoteCart(Cart $cart): bool
    {
        return $this->inspector->isQuoteCart($cart);
    }

    /**
     * The carrier the cart ships with when it can no longer be chosen: the one the quote names, else the one
     * the buyer was offered the choice of.
     */
    public function imposedCarrierId(Cart $cart): ?int
    {
        $quote = $this->inspector->quoteOf($cart);

        if (null === $quote) {
            return null;
        }

        $named = $quote->getDeliveryModuleId();

        if (null !== $named) {
            $module = ModuleQuery::create()->findPk($named);

            if ($module instanceof Module && BaseModule::DELIVERY_MODULE_TYPE === (int) $module->getType()) {
                return (int) $named;
            }
        }

        $held = $cart->getDeliveryModuleId();

        return null === $held ? null : (int) $held;
    }

    /**
     * The address the cart ships to when it can no longer be chosen: the one it holds. A quote that names
     * an address gets it written on arrival, so the cart holds it from the first render of the step.
     */
    public function imposedAddress(Cart $cart): ?CartAddress
    {
        return $this->inspector->isQuoteCart($cart) ? $cart->getCartAddressRelatedByAddressDeliveryId() : null;
    }

    /**
     * The address of the address book the quote ships to, when the cart does not hold it yet.
     */
    public function addressToApply(Cart $cart): ?int
    {
        $quote = $this->inspector->quoteOf($cart);

        return null === $quote || null !== $cart->getCartAddressRelatedByAddressDeliveryId() ? null : $this->guard->addressOf($quote, $cart);
    }

    /**
     * The carrier the quote names, when the cart does not hold it yet.
     */
    public function carrierToApply(Cart $cart): ?int
    {
        $quote = $this->inspector->quoteOf($cart);

        if (null === $quote || null !== $cart->getDeliveryModuleId()) {
            return null;
        }

        return $this->imposedCarrierId($cart);
    }
}
