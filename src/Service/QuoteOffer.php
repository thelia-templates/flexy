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

use FlexyBundle\DTO\QuoteOfferView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Quote\Cart\QuoteCartInspector;
use Thelia\Domain\Quote\Enum\QuoteMode;
use Thelia\Domain\Quote\Service\QuoteAccess;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;

/**
 * What the cart page offers about quotes, decided once per request from the setting of the shop.
 *
 * A cart that is itself a quote, accepted and waiting for its payment, is never asked for another quote
 * and is always payable, in the mode that sends every other cart through a quote. A visitor who has not
 * signed in is offered the request, and signs in on the way: the request is nominative. A signed-in buyer
 * whose role in a company does not let them ask for quotes is not offered it.
 *
 * Whether the direct way to the checkout is drawn is a courtesy: the core refuses the placement of an
 * ordinary cart in the mode that requires a quote, whatever the page shows.
 *
 * Keyed by the request itself, weakly, so a long-running worker never hands one buyer's offer to the next.
 */
final readonly class QuoteOffer
{
    /** @var \WeakMap<Request, QuoteOfferView> */
    private \WeakMap $offerByRequest;

    public function __construct(
        private SecurityContext $securityContext,
        private QuoteAccess $access,
        private QuoteCartInspector $inspector,
        private CartFacade $cartFacade,
        private RequestStack $requestStack,
    ) {
        $this->offerByRequest = new \WeakMap();
    }

    public function ofTheCart(): QuoteOfferView
    {
        $request = $this->requestStack->getMainRequest();

        if (null === $request) {
            return $this->decide();
        }

        return $this->offerByRequest[$request] ??= $this->decide();
    }

    private function decide(): QuoteOfferView
    {
        $mode = QuoteMode::fromConfig((string) ConfigQuery::read('quote_mode', QuoteMode::Disabled->value));

        // Whatever the mode now is: the cart of a quote accepted before it changed is still the cart in hand.
        $cart = $this->cartFacade->getCartFromSession();

        if (null !== $cart && $this->inspector->isQuoteCart($cart)) {
            return new QuoteOfferView($mode, inQuoteCart: true);
        }

        if (QuoteMode::Disabled === $mode) {
            return new QuoteOfferView($mode);
        }

        $customer = $this->securityContext->getCustomerUser();
        $signedIn = $customer instanceof Customer && !$customer->isGuest();

        return new QuoteOfferView(
            $mode,
            canRequest: !$signedIn || $this->access->canRequest($customer),
            checkoutAllowed: QuoteMode::Only !== $mode,
        );
    }
}
