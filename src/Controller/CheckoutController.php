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

namespace FlexyBundle\Controller;

use FlexyBundle\Components\Molecules\CheckoutSteps\Base as CheckoutSteps;
use FlexyBundle\Service\CartStockService;
use FlexyBundle\Service\GuestCheckoutGate;
use FlexyBundle\Service\GuestOrderTracking;
use FlexyBundle\Service\PlacedOrderMemory;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\HttpKernel\Exception\RedirectException;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Cart\Service\CartGuard;
use Thelia\Domain\Checkout\CheckoutFacade;
use Thelia\Domain\Checkout\DTO\CheckoutDTO;
use Thelia\Domain\Checkout\Exception\EmptyCartException;
use Thelia\Domain\Checkout\Exception\GuestCheckoutNotAllowedException;
use Thelia\Domain\Checkout\Exception\IncompleteInvoiceAddressException;
use Thelia\Domain\Checkout\Exception\InvalidDeliveryException;
use Thelia\Domain\Checkout\Exception\MissingAddressException;
use Thelia\Domain\Shipping\ShippingFacade;
use Thelia\Model\Order;

#[Route('/checkout', name: 'checkout_')]
class CheckoutController extends FlexyController
{
    #[Route('', name: 'no_route')]
    public function noRouteAction(): Response
    {
        return $this->generateRedirect('/checkout/cart');
    }

    #[Route('/cart', name: 'cart')]
    public function cartAction(
        CheckoutFacade $checkoutFacade,
        CartGuard $cartGuard,
        CartFacade $cartFacade,
    ): Response {
        $cart = $cartFacade->getOrCreateFromSession();
        $checkoutFacade->resetCheckout();
        $emptyCart = false;

        try {
            $cartGuard->checkCartNotEmpty($cart);
        } catch (EmptyCartException) {
            $emptyCart = true;
        }

        return $this->render('checkout-cart', [
            'emptyCart' => $emptyCart,
            'current' => CheckoutSteps::CART,
        ]);
    }

    #[Route('/delivery', name: 'delivery')]
    public function deliveryModesAction(
        CartFacade $cartFacade,
        CartGuard $cartGuard,
        ShippingFacade $shippingFacade,
        GuestCheckoutGate $guestCheckoutGate,
    ): Response {
        $this->checkCheckoutAccess($guestCheckoutGate);
        $cart = $cartFacade->getOrCreateFromSession();

        try {
            $cartGuard->checkCartNotEmpty($cart);

            if ($cart->isVirtual()) {
                $shippingFacade->setupVirtualDelivery($cart);
            }

            return $this->render('checkout-delivery', [
                'current' => CheckoutSteps::DELIVERY,
            ]);
        } catch (EmptyCartException $e) {
            throw new RedirectException($this->generateUrl('checkout_cart'), Response::HTTP_FOUND, $e->getMessage());
        }
    }

    #[Route('/payment', name: 'payment')]
    public function paymentAction(
        CartGuard $cartGuard,
        CartFacade $cartFacade,
        GuestCheckoutGate $guestCheckoutGate,
    ): Response {
        $this->checkCheckoutAccess($guestCheckoutGate);

        try {
            // Deliberately not guarded on the legal identifiers here: the billing address form
            // lives on this very page, so refusing to render it would leave the buyer with no
            // way to supply what is missing. The rule is enforced on leaving the step instead,
            // by CheckoutValidationService, and surfaced early by the NextButton.
            $cart = $cartFacade->getOrCreateFromSession();
            $cartGuard->checkCartNotEmpty($cart);
            $cartGuard->checkValidDelivery($cart);

            return $this->render('checkout-payment', [
                'current' => CheckoutSteps::PAYMENT,
            ]);
        } catch (EmptyCartException $e) {
            throw new RedirectException($this->generateUrl('checkout_cart'), Response::HTTP_FOUND, $e->getMessage());
        } catch (MissingAddressException|InvalidDeliveryException $e) {
            throw new RedirectException($this->generateUrl('checkout_delivery'), Response::HTTP_FOUND, $e->getMessage());
        }
    }

    #[Route('/gateway', name: 'gateway')]
    public function gatewayAction(GuestCheckoutGate $guestCheckoutGate): Response
    {
        $this->checkCheckoutAccess($guestCheckoutGate);

        return $this->render('checkout-gateway', [
            'current' => CheckoutSteps::GATEWAY,
        ]);
    }

    #[Route('/pay', name: 'pay')]
    public function payAction(
        CartFacade $cartFacade,
        CheckoutFacade $checkoutFacade,
        CartStockService $cartStockService,
        GuestCheckoutGate $guestCheckoutGate,
        GuestOrderTracking $guestOrderTracking,
    ): Response {
        try {
            $this->checkCheckoutAccess($guestCheckoutGate);

            $cart = $cartFacade->getCartFromSession();
            if (null === $cart) {
                throw new EmptyCartException();
            }

            $checkoutFacade->validateForOrder($cart);

            // validateForOrder() does not look at stock, and the core only re-checks it once the
            // order row exists — failing there leaves a dangling order and shows the visitor a raw
            // "REF : Not enough stock 2". Send them back to the cart, which spells out the shortage.
            if ($cartStockService->hasInsufficientStock($cart)) {
                return $this->generateRedirect($this->generateUrl('checkout_cart'));
            }

            $response = $checkoutFacade->pay(
                new CheckoutDTO(
                    cart: $cart,
                    deliveryModuleId: $cart->getDeliveryModuleId(),
                    // The cart columns hold `cart_address` ids, not customer `address`
                    // ids: read them through the facade, which resolves the copy.
                    deliveryAddressId: $cartFacade->getDeliveryAddressId(),
                    invoiceAddressId: $cartFacade->getInvoiceAddressId(),
                    paymentModuleId: $cart->getPaymentModuleId(),
                )
            );

            if ($response instanceof Response && $response->getStatusCode() === 200) {
                return $response;
            }

            return $this->render('checkout-confirm', [
                'current' => CheckoutSteps::CONFIRM,
                'guest_order_token' => $guestOrderTracking->tokenOfPlacedOrder(),
            ]);
        } catch (GuestCheckoutNotAllowedException) {
            // The shop's answer changed while the buyer was in the checkout: the setting
            // was turned off, or the cart gained a product that requires an account. Back
            // to the page that offers the ways in, which now offers the right ones — the
            // guest checkout being refused for this cart, that is the login page.
            throw new RedirectException(
                $this->generateUrl($guestCheckoutGate->entryPointRoute()),
                Response::HTTP_FOUND,
                $this->translator->trans('This order can no longer be placed without an account. Please sign in or create one.'),
            );
        } catch (EmptyCartException $e) {
            throw new RedirectException($this->generateUrl('checkout_cart'), Response::HTTP_FOUND, $e->getMessage());
        } catch (IncompleteInvoiceAddressException $e) {
            // Back to the payment step, which is where this theme puts the billing address
            // form: it opens on the selected address, ready to be completed.
            throw new RedirectException($this->generateUrl('checkout_payment'), Response::HTTP_FOUND, $e->getMessage());
        } catch (MissingAddressException|InvalidDeliveryException $e) {
            throw new RedirectException($this->generateUrl('checkout_delivery'), Response::HTTP_FOUND, $e->getMessage());
        }
    }

    #[Route('/confirm', name: 'confirm')]
    public function confirmAction(
        Session $session,
        EventDispatcherInterface $dispatcher,
        GuestCheckoutGate $guestCheckoutGate,
        GuestOrderTracking $guestOrderTracking,
        PlacedOrderMemory $placedOrderMemory,
    ): Response {
        // The core retires a guest from the session the moment their order exists, so by
        // the time a payment module sends them back here there is no customer left to
        // check. What stands in for it is the token of the order that was just placed:
        // it was signed for this session, it opens that order and nothing else. It is
        // dropped when the session changes hands, so it never names an order the person
        // now holding this browser did not place.
        $guestOrderToken = $guestOrderTracking->tokenOfPlacedOrder();

        if (null === $guestOrderToken) {
            $this->checkCheckoutAccess($guestCheckoutGate);
        }

        // Only for a session that actually placed an order. This page is reachable by
        // typing its url, and emptying the cart of someone halfway through the checkout
        // would throw away what they had put in it. The core already empties the cart on
        // the order itself, so there is nothing to lose by asking first.
        if ($placedOrderMemory->hasOne()) {
            $session->clearSessionCart($dispatcher);
        }

        return $this->render('checkout-confirm', [
            'current' => CheckoutSteps::CONFIRM,
            'guest_order_token' => $guestOrderToken,
        ]);
    }

    #[Route('/failed', name: 'failed')]
    public function failedAction(
        CheckoutFacade $checkoutFacade,
        Request $request,
        GuestOrderTracking $guestOrderTracking,
    ): Response {
        $order = $this->cancelFailedOrder(
            $checkoutFacade,
            $request->query->getInt('order_id'),
            $guestOrderTracking->tokenOfPlacedOrder(),
        );

        return $this->render('checkout-failed', [
            'current' => CheckoutSteps::FAILED,
            'failed_order_id' => $order?->getId(),
            'failed_order_message' => $request->query->get('message'),
        ]);
    }

    /**
     * Guards a checkout step: a signed-in customer, or a guest who identified themselves
     * on the way in, both pass.
     *
     * Deliberately not checkAuth(): that one answers "does this session hold an account",
     * which is the right question for the account pages and the wrong one here. A visitor
     * with neither is sent to the identification page when the shop lets this cart be
     * ordered without an account, and to the login page when it does not — which is
     * exactly where the checkout sent everyone before the guest checkout existed.
     */
    private function checkCheckoutAccess(GuestCheckoutGate $guestCheckoutGate): void
    {
        if ($guestCheckoutGate->mayEnterCheckout()) {
            return;
        }

        throw new RedirectException($this->generateUrl($guestCheckoutGate->entryPointRoute()));
    }

    /**
     * Takes back the order whose payment did not go through, and answers with it.
     *
     * A buyer coming back from a payment gateway may come back without the session they
     * left with: a gateway issuing the return itself, a browser dropping the cookie on a
     * cross-site redirect. Telling them the payment failed must not depend on that, so
     * this page is never guarded by a login.
     *
     * What does depend on it is the order, and the core cancellation is the one place
     * that decides: it accepts the customer the order names, or the tracking token this
     * session was handed when the order was placed — a guest no longer has the former by
     * the time they get here. Resolving the order here as well would spend the tracking
     * link's rate-limit budget twice on one page load, so the order this page reports is
     * the one the cancellation resolved and gave back.
     *
     * The refusals are all business ones, and they all leave the page as it is: an order
     * id that names nothing, one that belongs to somebody else, and one that is no longer
     * waiting for its payment — a gateway returning twice, a page refreshed, or a late
     * confirmation crossing a failure return. None of them is a server error, and none of
     * them may take the failure page down with it.
     */
    private function cancelFailedOrder(
        CheckoutFacade $checkoutFacade,
        int $orderId,
        ?string $guestOrderToken,
    ): ?Order {
        if ($orderId <= 0) {
            return null;
        }

        try {
            return $checkoutFacade->cancelOrder($orderId, $guestOrderToken);
        } catch (\InvalidArgumentException|PropelException $e) {
            $this->logger->info(\sprintf('Failed payment return: the order was not cancelled (%s).', $e->getMessage()));

            return null;
        }
    }
}
