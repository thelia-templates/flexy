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

use FlexyBundle\Service\CartStockService;
use FlexyBundle\Service\CheckoutModuleStepScreen;
use FlexyBundle\Service\CheckoutStepRouteResolver;
use FlexyBundle\Service\CheckoutStepRefusal;
use FlexyBundle\Service\CheckoutStockRefusal;
use FlexyBundle\Service\CheckoutTrail;
use FlexyBundle\Service\GuestCheckoutGate;
use FlexyBundle\Service\GuestOrderTracking;
use FlexyBundle\Service\PlacedOrderMemory;
use FlexyBundle\Service\PrefetchRequest;
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
use Thelia\Domain\Checkout\Exception\CheckoutException;
use Thelia\Domain\Checkout\Exception\EmptyCartException;
use Thelia\Domain\Checkout\Exception\GuestCheckoutNotAllowedException;
use Thelia\Domain\Checkout\Exception\InvalidPaymentException;
use Thelia\Domain\Checkout\Service\CheckoutProgressionService;
use Thelia\Domain\Customer\Service\AuthenticationReturnUrl;
use Thelia\Domain\Order\Exception\StockShortageException;
use Thelia\Model\Cart;
use Thelia\Model\CheckoutStep;
use Thelia\Model\Order;

/**
 * The checkout, as the shop configured it.
 *
 * No action of a step decides on its own whether the buyer may be there: it asks the
 * progression, which reads the `checkout_step` table and the cart in hand. A step the
 * merchant turned off, one left out for a cart with nothing to ship, and one that never
 * existed all answer the same thing — there is no such screen in this tunnel — and all
 * three send the buyer to the first step they still have something to do at. None of it
 * is a refusal: the refusals stay at the placement, where the core checks the whole
 * order however short the tunnel it was filled in through.
 */
#[Route('/checkout', name: 'checkout_')]
class CheckoutController extends FlexyController
{
    #[Route('', name: 'no_route')]
    public function noRouteAction(): Response
    {
        return $this->generateRedirect('/checkout/cart');
    }

    /**
     * @throws PropelException
     */
    #[Route('/cart', name: 'cart')]
    public function cartAction(
        CheckoutFacade $checkoutFacade,
        CartGuard $cartGuard,
        CartFacade $cartFacade,
        GuestCheckoutGate $guestCheckoutGate,
        CheckoutProgressionService $progression,
        CheckoutStepRouteResolver $routes,
        CheckoutTrail $trail,
    ): Response {
        $cart = $cartFacade->getOrCreateFromSession();

        // The trail has to name the step the "next" button actually leads to. A visitor
        // the session does not know yet is taken to the identification page whenever
        // this cart may be ordered without an account.
        $identifiesNext = !$guestCheckoutGate->mayEnterCheckout() && $guestCheckoutGate->isOfferedForCurrentCart();

        if ($routes->isOnePage()) {
            return $this->renderTheWholeTunnel($cart, $cartGuard, $checkoutFacade, $routes);
        }

        // A page fetched ahead of a click (hovering "Previous step" on the payment step) is not a visit to the
        // cart: it must not give the cart its delivery and its payment back under the buyer's feet.
        if (!PrefetchRequest::is($this->getRequest())) {
            $checkoutFacade->resetCheckout();

            // The reset gave the cart its delivery and its payment back: what the progression
            // answered a moment ago was about the cart as it was before that.
            $progression->forget();
        }

        $emptyCart = false;

        try {
            $cartGuard->checkCartNotEmpty($cart);
        } catch (EmptyCartException) {
            $emptyCart = true;
        }

        return $this->render('checkout-cart', [
            'emptyCart' => $emptyCart,
            'current' => CheckoutStep::CODE_CART,
            'steps' => $trail->of($cart, $identifiesNext),
            'next_step_url' => $routes->pathAfter($cart, CheckoutStep::CODE_CART),
        ]);
    }

    /**
     * @throws PropelException
     */
    #[Route('/delivery', name: 'delivery')]
    public function deliveryModesAction(
        CartFacade $cartFacade,
        GuestCheckoutGate $guestCheckoutGate,
        CheckoutProgressionService $progression,
        CheckoutStepRouteResolver $routes,
        CheckoutTrail $trail,
    ): Response {
        $this->checkCheckoutAccess($guestCheckoutGate);

        $cart = $cartFacade->getOrCreateFromSession();

        // On a one-page checkout the delivery is a section of the cart page, so this
        // route has no screen of its own left to serve.
        if ($routes->isOnePage() || !$progression->isReachable($cart, CheckoutStep::CODE_DELIVERY)) {
            return $this->generateRedirect($routes->pathOfTheFirstIncompleteStep($cart));
        }

        return $this->render('checkout-delivery', [
            'current' => CheckoutStep::CODE_DELIVERY,
            'steps' => $trail->of($cart),
            'next_step_url' => $routes->pathAfter($cart, CheckoutStep::CODE_DELIVERY),
            'previous_step_url' => $routes->pathBefore($cart, CheckoutStep::CODE_DELIVERY),
        ]);
    }

    /**
     * @throws PropelException
     */
    #[Route('/payment', name: 'payment')]
    public function paymentAction(
        CartFacade $cartFacade,
        CheckoutFacade $checkoutFacade,
        GuestCheckoutGate $guestCheckoutGate,
        CheckoutProgressionService $progression,
        CheckoutStepRouteResolver $routes,
        CheckoutTrail $trail,
    ): Response {
        $this->checkCheckoutAccess($guestCheckoutGate);

        $cart = $cartFacade->getOrCreateFromSession();

        if ($routes->isOnePage()) {
            return $this->generateRedirect($routes->pathOfTheFirstIncompleteStep($cart));
        }

        $checkoutFacade->settleVirtualDeliveryIfNeeded($cart);

        // Deliberately not guarded on the legal identifiers here: the billing address form
        // lives on this very page, so refusing to render it would leave the buyer with no
        // way to supply what is missing. Asking whether the step is reachable is exactly
        // that distinction — it runs the checks of the steps before this one, never its
        // own. The rule is enforced on leaving the step instead, by
        // CheckoutValidationService, and surfaced early by the NextButton.
        if (!$progression->isReachable($cart, CheckoutStep::CODE_PAYMENT)) {
            return $this->generateRedirect($routes->pathOfTheFirstIncompleteStep($cart));
        }

        return $this->render('checkout-payment', [
            'current' => CheckoutStep::CODE_PAYMENT,
            'steps' => $trail->of($cart),
            'next_step_url' => $routes->pathAfter($cart, CheckoutStep::CODE_PAYMENT),
            'previous_step_url' => $routes->pathBefore($cart, CheckoutStep::CODE_PAYMENT),
        ]);
    }

    /**
     * The screen of a step a module declared (`CheckoutStepProviderInterface`), drawn by the component the step names.
     *
     * One route for all of them, told apart by the code. A code that is no active step of this cart, or whose step
     * names no component, has no screen here, and a step the cart has not got to yet is not shown: the buyer goes back
     * to the step that still has something to do, as on the core steps.
     *
     * @throws PropelException
     */
    #[Route('/step/{code}', name: 'step', requirements: ['code' => CheckoutModuleStepScreen::CODE_PATTERN])]
    public function moduleStepAction(
        string $code,
        CartFacade $cartFacade,
        GuestCheckoutGate $guestCheckoutGate,
        CheckoutProgressionService $progression,
        CheckoutStepRouteResolver $routes,
        CheckoutTrail $trail,
    ): Response {
        $this->checkCheckoutAccess($guestCheckoutGate);

        $cart = $cartFacade->getOrCreateFromSession();

        $component = null;

        foreach ($progression->activeSteps($cart) as $step) {
            if ($step->code === $code) {
                $component = $step->componentName;

                break;
            }
        }

        if ($routes->isOnePage() || null === $component || '' === $component || !$progression->isReachable($cart, $code)) {
            return $this->generateRedirect($routes->pathOfTheFirstIncompleteStep($cart));
        }

        return $this->render('checkout-step', [
            'current' => $code,
            'steps' => $trail->of($cart),
            'step_component' => $component,
            'next_step_url' => $routes->pathAfter($cart, $code),
            'previous_step_url' => $routes->pathBefore($cart, $code),
        ]);
    }

    /**
     * @throws PropelException
     */
    #[Route('/gateway', name: 'gateway')]
    public function gatewayAction(
        GuestCheckoutGate $guestCheckoutGate,
        CheckoutTrail $trail,
    ): Response {
        $this->checkCheckoutAccess($guestCheckoutGate);

        return $this->render('checkout-gateway', [
            // Handing the money over is part of the payment step, not a stop of its own:
            // the bar stays where the buyer left it.
            'current' => CheckoutStep::CODE_PAYMENT,
            // Read the same way the confirmation and the failure pages read it: by the
            // time a gateway shows this page the order exists and the cart has been
            // emptied, and an empty cart is not a cart with nothing to ship — a bar
            // redrawn from it would put back the delivery step a virtual order never
            // made.
            'steps' => $trail->ofTheOrderJustPlaced(),
        ]);
    }

    /**
     * @throws PropelException
     */
    #[Route('/pay', name: 'pay')]
    public function payAction(
        CartFacade $cartFacade,
        CheckoutFacade $checkoutFacade,
        CartStockService $cartStockService,
        CheckoutStockRefusal $stockRefusal,
        CheckoutStepRefusal $stepRefusal,
        GuestCheckoutGate $guestCheckoutGate,
        GuestOrderTracking $guestOrderTracking,
        CheckoutStepRouteResolver $routes,
        CheckoutTrail $trail,
    ): Response {
        $cart = $cartFacade->getCartFromSession();

        try {
            $this->checkCheckoutAccess($guestCheckoutGate);

            if (null === $cart) {
                throw new EmptyCartException();
            }

            // Replayed here and not only on the step that leads to this one: on a
            // one-page checkout the cart can lose its last shippable line after the
            // sections above were settled, and the delivery step it was asked about is
            // gone by the time the order is placed. The carrier the order is refused
            // without is nobody else's job from here on.
            $checkoutFacade->settleVirtualDeliveryIfNeeded($cart);

            $checkoutFacade->validateForOrder($cart);

            // validateForOrder() does not look at stock, and the core only re-checks it once the
            // order row exists — failing there leaves a dangling order and shows the visitor a raw
            // "REF : Not enough stock 2". Send them back to the cart, which spells out the shortage.
            if ($cartStockService->hasInsufficientStock($cart)) {
                return $this->generateRedirect($routes->pathFor(CheckoutStep::CODE_CART));
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
                'current' => CheckoutStep::CODE_CONFIRMATION,
                // The cart was emptied by the placement a few lines ago: the bar is the
                // one the order was placed through, not the one an empty cart describes.
                'steps' => $trail->ofTheOrderJustPlaced(),
                'guest_order_token' => $guestOrderTracking->tokenOfPlacedOrder(),
            ]);
        } catch (GuestCheckoutNotAllowedException) {
            // The shop's answer changed while the buyer was in the checkout: the setting
            // was turned off, or the cart gained a product that requires an account. Back
            // to the page that offers the ways in, which now offers the right ones — the
            // guest checkout being refused for this cart, that is the login page.
            throw new RedirectException($this->generateUrl($guestCheckoutGate->entryPointRoute()), Response::HTTP_FOUND, $this->translator->trans('This order can no longer be placed without an account. Please sign in or create one.'));
        } catch (EmptyCartException $e) {
            throw new RedirectException($routes->pathFor(CheckoutStep::CODE_CART), Response::HTTP_FOUND, $e->getMessage());
        } catch (StockShortageException $e) {
            // The pre-check above covers the stock read before the placement; this one is the stock lost during it.
            throw $stockRefusal->answer($e, $routes->pathFor(CheckoutStep::CODE_CART), $this->getRequest()->getSession());
        } catch (InvalidPaymentException $e) {
            // The payment mode chosen cannot take this order any more (a module that went unavailable, a
            // mode a module refuses for the cart, a gift card that can no longer pay its share): the buyer
            // picks again on the payment step, with the reason, and not a 500.
            $this->addFlash('error', $e->getMessage());

            throw new RedirectException($routes->pathFor(CheckoutStep::CODE_PAYMENT), Response::HTTP_FOUND, $e->getMessage());
        } catch (CheckoutException $e) {
            // The rule, not the greyed-out button: a request that reaches here without a
            // carrier, without the legal identifiers of a business invoice or without the
            // boxes ticked — a typed url, a consent the shop made mandatory while the page
            // was open — places no order. The same goes for the step of a module (a phone
            // number, a gift message): its refusal is a CheckoutException too, and reaching
            // the end of the list of the core ones is no reason for a 500.
            //
            // Where it lands is the progression's answer rather than one written per
            // exception: the step that still has something missing is the step the buyer
            // has to be on, and on a one-page checkout it is the only screen there is.
            //
            // The core redirect listener only reads the url and the status off
            // RedirectException, dropping its message: a flash is what actually gets the
            // wording — which names the consent, or the field — onto the page they land on.
            // A cart there is none of raises EmptyCartException, which the catch above
            // answers: by here there is one to read the progression off.
            throw $stepRefusal->answer($e, $routes->pathOfTheFirstIncompleteStep($cart), $this->getRequest()->getSession());
        }
    }

    /**
     * @throws PropelException
     */
    #[Route('/confirm', name: 'confirm')]
    public function confirmAction(
        Session $session,
        EventDispatcherInterface $dispatcher,
        GuestCheckoutGate $guestCheckoutGate,
        GuestOrderTracking $guestOrderTracking,
        PlacedOrderMemory $placedOrderMemory,
        CheckoutTrail $trail,
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

        // The tunnel the order was placed through, taken at the placement: this page is
        // read after the cart was emptied — sometimes several requests later, on the way
        // back from a payment gateway — and an empty cart describes another tunnel than
        // the one the buyer walked.
        $steps = $trail->ofTheOrderJustPlaced();

        // Only for a session that actually placed an order. This page is reachable by
        // typing its url, and emptying the cart of someone halfway through the checkout
        // would throw away what they had put in it. The core already empties the cart on
        // the order itself, so there is nothing to lose by asking first.
        if ($placedOrderMemory->hasOne()) {
            $session->clearSessionCart($dispatcher);
        }

        return $this->render('checkout-confirm', [
            'current' => CheckoutStep::CODE_CONFIRMATION,
            'steps' => $steps,
            'guest_order_token' => $guestOrderToken,
        ]);
    }

    /**
     * @throws PropelException
     */
    #[Route('/failed', name: 'failed')]
    public function failedAction(
        CheckoutFacade $checkoutFacade,
        Request $request,
        GuestOrderTracking $guestOrderTracking,
        CheckoutTrail $trail,
    ): Response {
        $order = $this->cancelFailedOrder(
            $checkoutFacade,
            $request->query->getInt('order_id'),
            $guestOrderTracking->tokenOfPlacedOrder(),
        );

        return $this->render('checkout-failed', [
            // A payment that did not go through leaves the buyer on the payment step: it
            // is what they have to do again.
            'current' => CheckoutStep::CODE_PAYMENT,
            // The order exists — it is the one just cancelled — so the cart is gone and
            // the bar is the tunnel that order was placed through.
            'steps' => $trail->ofTheOrderJustPlaced(),
            'failed_order_id' => $order?->getId(),
            'failed_order_message' => $request->query->get('message'),
        ]);
    }

    /**
     * The whole tunnel on one page, which is what the shop asked for.
     *
     * Deliberately without resetCheckout(): on a checkout of several screens the cart
     * page is the one the buyer walks forward from, and dropping the carrier and the
     * payment there is how going back undoes a choice. Here that page *is* the delivery
     * and the payment, so a reset would erase both on every reload and leave the
     * sections below permanently locked.
     *
     * @throws PropelException
     */
    private function renderTheWholeTunnel(
        Cart $cart,
        CartGuard $cartGuard,
        CheckoutFacade $checkoutFacade,
        CheckoutStepRouteResolver $routes,
    ): Response {
        $checkoutFacade->settleVirtualDeliveryIfNeeded($cart);

        $emptyCart = false;

        try {
            $cartGuard->checkCartNotEmpty($cart);
        } catch (EmptyCartException) {
            $emptyCart = true;
        }

        return $this->render('checkout-onepage', [
            'emptyCart' => $emptyCart,
            'pay_step' => $routes->lastStepToSettle($cart),
            'next_step_url' => $this->generateUrl('checkout_pay'),
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
        if (!$guestCheckoutGate->mayEnterCheckout()) {
            // A cart that cannot be ordered without an account is a refusal worth naming,
            // and it has one way out: signing in. The identification page needs nothing —
            // its own form sends the visitor on to the delivery step.
            if ($guestCheckoutGate->isRefusedByTheCart()) {
                throw $this->signInFirstBecauseAProductNeedsAnAccount();
            }

            $entryPoint = $guestCheckoutGate->entryPointRoute();

            // The step being guarded travels with the redirection to the login page, so that
            // signing in comes back to the checkout instead of the account pages.
            throw new RedirectException($this->generateUrl($entryPoint, 'customer_login' === $entryPoint ? [AuthenticationReturnUrl::PARAMETER => $this->getRequest()->getRequestUri()] : []));
        }

        // Asked again on every step, not only on the way in: a guest already in the
        // checkout may add such a product afterwards, and the cart is what decides. Left
        // to the last click, the buyer would fill in delivery and payment for an order
        // that was never going to be placed.
        if ($guestCheckoutGate->isCheckingOutAsAGuest() && $guestCheckoutGate->isRefusedByTheCart()) {
            throw $this->signInFirstBecauseAProductNeedsAnAccount();
        }
    }

    /**
     * The sign-in page, carrying the reason and the way back.
     *
     * Without the reason the page appears out of nowhere and reads as the shop having
     * changed its mind; without the way back, signing in lands on the account pages and
     * the buyer has to find their cart again.
     */
    private function signInFirstBecauseAProductNeedsAnAccount(): RedirectException
    {
        $this->addFlash(
            'information',
            $this->translator->trans('One of the products in your cart requires an account. Please sign in or create one to place this order.'),
        );

        return new RedirectException($this->generateUrl(
            'customer_login',
            [AuthenticationReturnUrl::PARAMETER => $this->getRequest()->getRequestUri()],
        ));
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
