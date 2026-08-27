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
use Thelia\Domain\Checkout\Exception\IncompleteInvoiceAddressException;
use Thelia\Domain\Checkout\Exception\InvalidDeliveryException;
use Thelia\Domain\Checkout\Exception\MissingAddressException;
use Thelia\Domain\Shipping\ShippingFacade;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;

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
    ): Response {
        $this->checkAuth();
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
    ): Response {
        $this->checkAuth();

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
    public function gatewayAction(): Response
    {
        $this->checkAuth();

        return $this->render('checkout-gateway', [
            'current' => CheckoutSteps::GATEWAY,
        ]);
    }

    #[Route('/pay', name: 'pay')]
    public function payAction(
        CartFacade $cartFacade,
        CheckoutFacade $checkoutFacade,
        CartStockService $cartStockService,
    ): Response {
        try {
            $this->checkAuth();

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
            ]);
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
    public function confirmAction(Session $session, EventDispatcherInterface $dispatcher): Response
    {
        $this->checkAuth();

        $session->clearSessionCart($dispatcher);

        return $this->render('checkout-confirm', [
            'current' => CheckoutSteps::CONFIRM,
        ]);
    }

    #[Route('/failed', name: 'failed')]
    public function failedAction(CheckoutFacade $checkoutFacade, Request $request): Response
    {
        $order = $this->failedOrderOf($request->query->getInt('order_id'));

        // Only an order still waiting for its payment is cancelled. A payment module that
        // already gave up on it, a gateway returning twice, or the buyer refreshing this
        // page then find nothing left to cancel, and an order paid in the meantime — a
        // late confirmation crossing a failure return — is never taken back.
        if ($order instanceof Order && $order->isNotPaid()) {
            $checkoutFacade->cancelOrder($order->getId());
        }

        return $this->render('checkout-failed', [
            'current' => CheckoutSteps::FAILED,
            'failed_order_id' => $order?->getId(),
            'failed_order_message' => $request->query->get('message'),
        ]);
    }

    /**
     * A buyer coming back from a payment gateway may come back without the session they
     * left with: a gateway issuing the return itself, a browser dropping the cookie on a
     * cross-site redirect. Telling them the payment failed must not depend on that, so
     * this page is never guarded by a login.
     *
     * What does depend on it is the order: nothing is read, cancelled or shown unless the
     * session holds the customer it belongs to. Walking the order ids from here answers
     * the same page whether the order exists or not.
     */
    private function failedOrderOf(int $orderId): ?Order
    {
        $customerId = $this->getSecurityContext()->getCustomerUser()?->getId();

        if ($orderId <= 0 || null === $customerId) {
            return null;
        }

        return OrderQuery::create()->filterByCustomerId($customerId)->findPk($orderId);
    }
}
