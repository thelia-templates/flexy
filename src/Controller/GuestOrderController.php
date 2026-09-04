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

use FlexyBundle\Form\GuestAccountCreationForm;
use FlexyBundle\Service\GuestOrderTracking;
use FlexyBundle\Service\GuestOrderView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Product\VirtualProductOrderDownloadResponseEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Customer\CustomerFacade;
use Thelia\Domain\Customer\Exception\GuestCheckoutEmailAlreadyRegisteredException;
use Thelia\Domain\Customer\Exception\NotAGuestCustomerException;
use Thelia\Form\BaseForm;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProductQuery;

/**
 * The way back to an order placed without an account.
 *
 * The link is the whole of the authentication, so every page here reads exactly one
 * order — the one the token names — and never queries by customer. A token that names
 * no order, one whose signature does not match, one that has expired and one presented
 * once too often all answer the same 404: none of them says whether the order exists.
 */
#[Route('/order/track', name: 'guest_order_')]
class GuestOrderController extends FlexyController
{
    #[Route('/{token}', name: 'track', methods: ['GET'])]
    public function track(
        string $token,
        GuestOrderTracking $guestOrderTracking,
        GuestOrderView $guestOrderView,
    ): Response {
        $order = $this->orderOfToken($token, $guestOrderTracking);

        return $this->render('guest-order', [
            'token' => $token,
            'order' => $guestOrderView->build($order),
            'may_create_account' => self::mayCreateAccount($order),
        ]);
    }

    /**
     * The invoice of that one order, behind the same token as the page that links to it.
     *
     * Deliberately not the account route: that one resolves the order through the signed-in
     * customer, which a guest is not.
     */
    #[Route('/{token}/invoice', name: 'invoice', methods: ['GET'])]
    public function invoice(
        string $token,
        GuestOrderTracking $guestOrderTracking,
        EventDispatcherInterface $eventDispatcher,
    ): Response {
        $order = $this->orderOfToken($token, $guestOrderTracking);

        return $this->generateOrderPdf(
            $eventDispatcher,
            (int) $order->getId(),
            ConfigQuery::read('pdf_invoice_file', 'invoice'),
            checkOrderStatus: true,
            checkAdminUser: true,
        );
    }

    /**
     * The file bought with a virtual product, behind the same token as the page that
     * links to it.
     *
     * Deliberately not the account route: that one asks for a signed-in customer, which a
     * guest is not, so the button the tracking page used to show led nowhere. The line has
     * to belong to the order the token names — an id from another order answers the same
     * 404 as an unknown one — and the theme never reads the file itself: it says which
     * line is being asked for, and the module that stores documents answers with it.
     */
    #[Route('/{token}/download/{orderProductId}', name: 'download', requirements: ['orderProductId' => '\d+'], methods: ['GET'])]
    public function download(
        string $token,
        int $orderProductId,
        GuestOrderTracking $guestOrderTracking,
        EventDispatcherInterface $eventDispatcher,
    ): Response {
        $order = $this->orderOfToken($token, $guestOrderTracking);

        $orderProduct = OrderProductQuery::create()
            ->filterByOrderId($order->getId())
            ->findPk($orderProductId)
        ;

        // A line of another order, an unknown id, an order that is not paid for and a line
        // no document was ever attached to all answer the same 404: none of them tells
        // whether the file is there to be had. `virtual` alone is not enough — it says the
        // product was sold as a file, not that one was attached to the line.
        if (null === $orderProduct
            || null === $orderProduct->getVirtualDocument()
            || !$order->isPaid(false)
        ) {
            throw new NotFoundHttpException();
        }

        $event = new VirtualProductOrderDownloadResponseEvent($orderProduct);
        $eventDispatcher->dispatch($event, TheliaEvents::VIRTUAL_PRODUCT_ORDER_DOWNLOAD_RESPONSE);

        $response = $event->getResponse();

        // No module answered, so there is no file to serve. A shop without a virtual
        // product module is a 404 here, not a 500.
        if (!$response instanceof Response) {
            throw new NotFoundHttpException();
        }

        return $response;
    }

    #[Route('/{token}/account', name: 'account', methods: ['GET'])]
    public function account(string $token, GuestOrderTracking $guestOrderTracking): Response
    {
        $order = $this->orderOfToken($token, $guestOrderTracking);

        if (!self::mayCreateAccount($order)) {
            return $this->generateRedirect($this->generateUrl('guest_order_track', ['token' => $token]));
        }

        return $this->render('guest-account', [
            'token' => $token,
            'email' => $order->getCustomer()?->getEmail(),
        ]);
    }

    /**
     * Turns the passwordless account the order was placed under into a real one.
     *
     * The link the buyer holds is signed with the password hash of that account, so it
     * stops working the moment this succeeds. What the buyer gets from here on is not a
     * session — the converted account is created disabled, exactly like any other
     * registration on a shop that confirms addresses, and mailed an activation code —
     * but the pending registration the activation page already knows how to read, so
     * the buyer is pointed at the very flow a fresh signup goes through.
     */
    #[Route('/{token}/account', name: 'account_create', methods: ['POST'])]
    public function createAccount(
        string $token,
        GuestOrderTracking $guestOrderTracking,
        CustomerFacade $customerFacade,
        SessionInterface $session,
    ): Response {
        $order = $this->orderOfToken($token, $guestOrderTracking);
        $customer = $order->getCustomer();

        if (null === $customer || !self::mayCreateAccount($order)) {
            return $this->generateRedirect($this->generateUrl('guest_order_track', ['token' => $token]));
        }

        /** @var BaseForm $form */
        $form = $this->createForm(GuestAccountCreationForm::FORM_NAME);

        try {
            $validatedForm = $this->validateForm($form, Request::METHOD_POST);

            $completedCustomer = $customerFacade->convertGuestToCustomer(
                $customer,
                (string) $validatedForm->get('password')->getData(),
            );

            // Same key the registration controller writes: the activation page reads a
            // pending registration from there, whichever flow put it there.
            $session->set('registration_customer_id', $completedCustomer->getId());
            $guestOrderTracking->forgetPlacedOrder();

            $this->addFlash(
                'information',
                $this->translator->trans('Your account has been created. Enter the activation code we sent to %email to finish.', ['%email' => (string) $completedCustomer->getEmail()]),
            );

            // Answered with a redirect, not with the page itself: the link that led here
            // is signed against the password hash that has just changed, so refreshing
            // this POST would replay it onto a token that no longer opens anything.
            return $this->generateRedirect($this->generateUrl('customer_activation'), status: Response::HTTP_SEE_OTHER);
        } catch (NotAGuestCustomerException) {
            // The account was completed in the meantime — a second tab, a link followed
            // twice. Nothing to do here, and nothing lost: signing in is the way in now.
            return $this->generateRedirect($this->generateUrl('customer_login'));
        } catch (GuestCheckoutEmailAlreadyRegisteredException) {
            $message = $this->translator->trans('This email address already has an account. Please sign in instead.');
        } catch (FormValidationException $e) {
            $message = $this->translator->trans('Please check your input: %s', ['%s' => $e->getMessage()]);
        }

        $form->setErrorMessage($message);
        $this->getParserContext()->addForm($form);

        return $this->render('guest-account', [
            'token' => $token,
            'email' => $customer->getEmail(),
        ]);
    }

    /**
     * The order a token names, or a 404 that says nothing more.
     */
    private function orderOfToken(string $token, GuestOrderTracking $guestOrderTracking): Order
    {
        $order = $guestOrderTracking->findOrder($token);

        if (!$order instanceof Order) {
            throw new NotFoundHttpException();
        }

        return $order;
    }

    /**
     * Only an order still hanging off a passwordless account has an account to offer.
     */
    private static function mayCreateAccount(Order $order): bool
    {
        return true === $order->getCustomer()?->isGuest();
    }
}
