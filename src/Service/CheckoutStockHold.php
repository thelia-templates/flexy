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

use Thelia\Domain\Catalog\Stock\Exception\StockReservationRateLimitedException;
use Thelia\Domain\Checkout\CheckoutFacade;
use Thelia\Domain\Order\Exception\StockShortageException;
use Thelia\Model\Cart;

/**
 * Keeps the stock of the cart held while the buyer makes their choices on a page that never changes screen.
 *
 * On the one-page checkout the delivery, the billing address and the payment are chosen in live components, which write
 * the cart through the cart facade: nothing in that path holds the stock, so a buyer who stays on the page for a while
 * would see their hold run out under them. Each choice holds it again, and a line another cart has taken since is a
 * sentence for the component to show, never a server error.
 */
final readonly class CheckoutStockHold
{
    public function __construct(
        private CheckoutFacade $checkoutFacade,
        private CheckoutStockRefusal $stockRefusal,
    ) {
    }

    /**
     * @return string|null the sentence for the buyer when a line of the cart is held by another cart or the cart holds too often, null when the stock is held
     */
    public function extend(Cart $cart): ?string
    {
        try {
            $this->checkoutFacade->holdStock($cart);
        } catch (StockReservationRateLimitedException $rateLimited) {
            // Not a shortage: the cart holds more often than a buyer does. The live response stays a 200 carrying the message:
            // a non-2xx answer would not redraw the component, so the buyer would never read how long to wait. The 429 with
            // Retry-After is kept for the API and the express checkout, which answer to a client and not to a page.
            return $this->stockRefusal->sentenceOfRateLimit($rateLimited);
        } catch (StockShortageException $shortage) {
            return $this->stockRefusal->sentenceOf($shortage);
        }

        return null;
    }
}
