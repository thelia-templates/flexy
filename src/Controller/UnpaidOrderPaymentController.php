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

use FlexyBundle\Service\GuestCheckoutGate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Domain\Cart\Service\CartContext;
use Thelia\Domain\Customer\Service\AuthenticationReturnUrl;
use Thelia\Domain\Order\Reminder\UnpaidOrderPaymentLink;
use Thelia\Model\CartQuery;

/**
 * The link of a payment reminder: it puts the cart the order was placed from back in the
 * session and opens the payment step, where paying again goes to the same order.
 *
 * A guest has no account to sign into, so the link is enough. An order placed from an
 * account asks for that account first: the link never signs anybody in, and a reminder
 * forwarded to someone else does not hand them the account.
 */
class UnpaidOrderPaymentController extends FlexyController
{
    #[Route('/order/pay/{token}', name: 'order_payment_resume', methods: ['GET'])]
    public function resume(
        string $token,
        UnpaidOrderPaymentLink $paymentLink,
        GuestCheckoutGate $guestCheckoutGate,
        CartContext $cartContext,
    ): Response {
        $order = $paymentLink->findOrderForToken($token);
        $cart = null !== $order ? CartQuery::create()->findPk($order->getCartId()) : null;
        $customer = $order?->getCustomer();

        if (null === $order || null === $cart || null === $customer) {
            $this->addFlash('warning', $this->translator->trans('This payment link no longer opens an order to pay: it was paid, cancelled, or the link has expired.'));

            return $this->generateRedirect($this->generateUrl('checkout_cart'));
        }

        $current = $this->securityContext->getCustomerUser();

        if (!$customer->isGuest()) {
            if (!$this->securityContext->hasAuthenticatedCustomerUser()) {
                $this->addFlash('information', $this->translator->trans('Sign in to complete the payment of your order %ref.', ['%ref' => (string) $order->getRef()]));

                return $this->generateRedirect($this->generateUrl('customer_login', [
                    AuthenticationReturnUrl::PARAMETER => $this->getRequest()->getRequestUri(),
                ]));
            }

            if ((int) $current?->getId() !== (int) $customer->getId()) {
                $this->addFlash('warning', $this->translator->trans('This payment link belongs to another account.'));

                return $this->generateRedirect($this->generateUrl('checkout_cart'));
            }
        }

        // The cart first: signing the guest in attaches it to the cart of the session.
        $cartContext->addCartSession($cart);

        if ($customer->isGuest() && (int) $current?->getId() !== (int) $customer->getId()) {
            $guestCheckoutGate->signIn($customer, array_values(array_filter([$cart->getAddressDeliveryId(), $cart->getAddressInvoiceId()])));
        }

        return $this->generateRedirect($this->generateUrl('checkout_payment'));
    }
}
