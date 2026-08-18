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
use Thelia\Domain\Checkout\Exception\InvalidDeliveryException;
use Thelia\Domain\Checkout\Exception\MissingAddressException;
use Thelia\Domain\Shipping\ShippingFacade;

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
        $checkoutFacade->resetCheckout($cart);
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
        $this->checkAuth();

        $orderId = (int) $request->query->get('order_id');
        $message = $request->query->get('message');

        try {
            $checkoutFacade->cancelOrder($orderId);
        } catch (\InvalidArgumentException $e) {
            throw new RedirectException($this->generateUrl('checkout_cart'), Response::HTTP_FOUND, $e->getMessage());
        }

        return $this->render('checkout-failed', [
            'current' => CheckoutSteps::FAILED,
            'failed_order_id' => $orderId,
            'failed_order_message' => $message,
        ]);
    }
}
