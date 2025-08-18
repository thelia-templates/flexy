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

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\HttpKernel\Exception\RedirectException;
use Thelia\Core\Translation\Translator;
use Thelia\Exception\Checkout\EmptyCartException;
use Thelia\Exception\Checkout\InvalidDeliveryException;
use Thelia\Exception\Checkout\MissingAddressException;
use Thelia\Log\Tlog;
use Thelia\Service\Model\CartService;
use Thelia\Service\Model\CheckoutService;
use Thelia\Service\Model\DeliveryService;

#[Route('/checkout', name: 'checkout_')]
class CheckoutController extends FlexyController
{
    public const STEP_CART = 'cart';
    public const STEP_DELIVERY = 'delivery';
    public const STEP_PAYMENT = 'payment';
    public const STEP_GATEWAY = 'gateway';
    public const STEP_CONFIRM = 'confirm';
    public const STEPS = [
        self::STEP_CART => 1,
        self::STEP_DELIVERY => 2,
        self::STEP_PAYMENT => 3,
        self::STEP_GATEWAY => 3,
        self::STEP_CONFIRM => 4,
    ];

    #[Route('', name: 'no_route')]
    public function noRouteAction(): Response
    {
        return $this->pageNotFound();
    }

    #[Route('/cart', name: 'cart')]
    public function cartAction(CheckoutService $checkoutService): Response
    {
        $checkoutService->resetCheckout();

        return $this->render('checkout', [
            'page' => self::STEP_CART,
            'current' => self::STEPS[self::STEP_CART],
        ]);
    }

    #[Route('/delivery', name: 'delivery')]
    public function deliveryAction(CartService $cartService, DeliveryService $deliveryService): Response
    {
        $this->checkAuth();
        try {
            $cartService->checkCartNotEmpty();

            $cart = $cartService->getCart();
            if ($cart->isVirtual()) {
                $deliveryService->setupVirtualDelivery();
            }

            return $this->render('checkout', [
                'page' => self::STEP_DELIVERY,
                'current' => self::STEPS[self::STEP_DELIVERY],
            ]);
        } catch (EmptyCartException $e) {
            throw new RedirectException($this->generateUrl('checkout_cart'), Response::HTTP_FOUND, $e->getMessage());
        } catch (\Exception $e) {
            Tlog::getInstance()->error(\sprintf('Failed to set delivery part : %s', $e->getMessage()));

            throw new RedirectException($this->generateUrl('checkout_cart'), Response::HTTP_FOUND, Translator::getInstance()->trans('Critical delivery error, check logs for more information !'));
        }
    }

    #[Route('/payment', name: 'payment')]
    public function paymentAction(CartService $cartService): Response
    {
        $this->checkAuth();
        try {
            $cartService->checkCartNotEmpty();
            $cartService->checkValidDelivery();

            return $this->render('checkout', [
                'page' => self::STEP_PAYMENT,
                'current' => self::STEPS[self::STEP_PAYMENT],
            ]);
        } catch (EmptyCartException $e) {
            throw new RedirectException($this->generateUrl('checkout_cart'), Response::HTTP_FOUND, $e->getMessage());
        } catch (MissingAddressException|InvalidDeliveryException $e) {
            throw new RedirectException($this->generateUrl('checkout_delivery'), Response::HTTP_FOUND, $e->getMessage());
        } catch (\Exception $e) {
            Tlog::getInstance()->error(\sprintf('Failed to set payment part : %s', $e->getMessage()));

            throw new RedirectException($this->generateUrl('checkout_cart'), Response::HTTP_FOUND, Translator::getInstance()->trans('Critical payment error, check logs for more information !'));
        }
    }

    #[Route('/gateway', name: 'gateway')]
    public function gatewayAction(): Response
    {
        $this->checkAuth();

        return $this->render('checkout', [
            'page' => self::STEP_GATEWAY,
            'current' => self::STEPS[self::STEP_GATEWAY],
        ]);
    }

    #[Route('/confirm', name: 'confirm')]
    public function confirmAction(): Response
    {
        $this->checkAuth();

        return $this->render('checkout', [
            'page' => self::STEP_CONFIRM,
            'current' => self::STEPS[self::STEP_CONFIRM],
        ]);
    }
}
