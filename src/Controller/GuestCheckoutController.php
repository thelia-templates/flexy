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
use FlexyBundle\Form\GuestCheckoutForm;
use FlexyBundle\Service\GuestAddressCreator;
use FlexyBundle\Service\GuestCheckoutGate;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Api\Security\GuestRegistrationLimiter;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Cart\Service\CartGuard;
use Thelia\Domain\Checkout\Exception\EmptyCartException;
use Thelia\Domain\Customer\CustomerFacade;
use Thelia\Domain\Customer\DTO\CustomerGuestDTO;
use Thelia\Domain\Customer\EmailAddress;
use Thelia\Domain\Customer\Exception\GuestCheckoutEmailAlreadyRegisteredException;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Form\BaseForm;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\Customer;

/**
 * Where a visitor with a cart and no session says who they are.
 *
 * Three ways out, and the shop decides how many are on offer: signing in, opening an
 * account, or ordering without one. The third disappears the moment the setting is off
 * or the cart holds a product the shop marked as needing an account — the page is then
 * the same choice the checkout has always offered.
 */
#[Route('/checkout', name: 'checkout_')]
class GuestCheckoutController extends FlexyController
{
    #[Route('/identify', name: 'identify', methods: ['GET'])]
    public function identify(
        GuestCheckoutGate $guestCheckoutGate,
        CartFacade $cartFacade,
        CartGuard $cartGuard,
    ): Response {
        // Nothing left to ask of someone the session already knows, guest or not.
        if ($guestCheckoutGate->mayEnterCheckout()) {
            return $this->generateRedirect($this->generateUrl('checkout_delivery'));
        }

        // There is nothing to identify oneself for without a cart, and every way out of
        // this page leads to a step that would bounce straight back to the cart anyway.
        if ($this->cartIsEmpty($cartFacade, $cartGuard)) {
            return $this->generateRedirect($this->generateUrl('checkout_cart'));
        }

        return $this->renderIdentificationPage($guestCheckoutGate);
    }

    #[Route('/identify', name: 'identify_guest', methods: ['POST'])]
    public function identifyAsGuest(
        GuestCheckoutGate $guestCheckoutGate,
        CustomerFacade $customerFacade,
        GuestAddressCreator $guestAddressCreator,
        LangService $langService,
        GuestRegistrationLimiter $guestRegistrationLimiter,
        SessionInterface $session,
    ): Response {
        // A session that already holds someone is never taken back down to a guest. The
        // form is served to visitors with no session at all, so a submission arriving
        // with one is either a stale page or a forged post, and honouring it would swap
        // a signed-in customer for a passwordless row — quietly, on a POST that carries
        // no credential.
        if ($guestCheckoutGate->mayEnterCheckout()) {
            return $this->generateRedirect($this->generateUrl('checkout_delivery'));
        }

        // Asked again on the submission, not only on the page that offered it: between
        // the two the shop may have turned the setting off, or the cart may have gained
        // a product that forbids ordering without an account.
        if (!$guestCheckoutGate->isOfferedForCurrentCart()) {
            return $this->generateRedirect($this->generateUrl('customer_login'));
        }

        /** @var BaseForm $form */
        $form = $this->createForm(GuestCheckoutForm::FORM_NAME);

        try {
            $validatedForm = $this->validateForm($form, Request::METHOD_POST);
        } catch (FormValidationException $e) {
            return $this->renderIdentificationPageWithError(
                $guestCheckoutGate,
                $form,
                $this->translator->trans('Please check your input: %s', ['%s' => $e->getMessage()]),
            );
        }

        $data = $validatedForm->getData();

        // Consumed before the address is looked up: this endpoint takes no credential and
        // writes a customer row, so it must not be usable to fill the table nor to probe,
        // one address at a time, which of them already have an account.
        if (!$guestRegistrationLimiter->allows((string) $data['email'])) {
            return $this->renderIdentificationPageWithError(
                $guestCheckoutGate,
                $form,
                $this->translator->trans('Too many attempts. Please try again later.'),
            );
        }

        $awaitingActivation = $guestCheckoutGate->accountAwaitingActivationFor(
            EmailAddress::normalize((string) $data['email']),
        );

        if ($awaitingActivation instanceof Customer) {
            return $this->sendToActivation($awaitingActivation, $session);
        }

        try {
            $guest = $customerFacade->registerGuest(new CustomerGuestDTO(
                email: $data['email'],
                firstname: $data['firstname'],
                lastname: $data['lastname'],
                title: null === ($data['title'] ?? null) ? null : (int) $data['title'],
                langId: $langService->getLang()?->getId(),
            ));
        } catch (GuestCheckoutEmailAlreadyRegisteredException) {
            return $this->renderIdentificationPageWithError(
                $guestCheckoutGate,
                $form,
                $this->translator->trans('This email address already has an account. Please sign in to place your order.'),
                signInFirst: true,
            );
        }

        $guestCheckoutGate->signIn($guest, $this->createAddresses($guestAddressCreator, $guest, $data));

        return $this->generateRedirect($this->generateUrl('checkout_delivery'));
    }

    /**
     * Points a buyer who chose a password and never answered the code at the only page
     * that can take them any further.
     *
     * Their row is still a guest, so the login provider does not see it and the login
     * form can only tell them their password is wrong. No code is sent from here — the
     * activation page has its own, rate-limited, "send me another one".
     */
    private function sendToActivation(Customer $awaitingActivation, SessionInterface $session): Response
    {
        // The same key the registration writes: the activation page reads a pending
        // registration from there, whichever flow put it there.
        $session->set('registration_customer_id', $awaitingActivation->getId());

        $this->addFlash(
            'information',
            $this->translator->trans('Your account is waiting for its activation code. Enter it below to finish, then place your order.'),
        );

        return $this->generateRedirect($this->generateUrl('customer_activation'));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<int> the addresses written for this identification
     */
    private function createAddresses(
        GuestAddressCreator $guestAddressCreator,
        Customer $guest,
        array $data,
    ): array {
        $addressIds = [];

        $addressIds[] = $guestAddressCreator->create(
            $guest,
            $data,
            $this->translator->trans('Delivery address'),
            (int) $data['title'],
            (string) $data['firstname'],
            (string) $data['lastname'],
            // The delivery step selects the default address on its own, and a guest has
            // exactly one address book: without this the step opens on nothing selected.
            isDefault: true,
        );

        if (true === ($data['invoice_same'] ?? false)) {
            return $addressIds;
        }

        $addressIds[] = $guestAddressCreator->create(
            $guest,
            self::billingAddressOf($data),
            $this->translator->trans('Billing address'),
            (int) $data['invoice_title'],
            (string) $data['invoice_firstname'],
            (string) $data['invoice_lastname'],
            isDefault: false,
        );

        return $addressIds;
    }

    /**
     * @throws PropelException
     */
    private function cartIsEmpty(CartFacade $cartFacade, CartGuard $cartGuard): bool
    {
        $cart = $cartFacade->getCartFromSession();

        if (null === $cart) {
            return true;
        }

        try {
            $cartGuard->checkCartNotEmpty($cart);
        } catch (EmptyCartException) {
            return true;
        }

        return false;
    }

    /**
     * The billing block, under the plain address names the address creation expects.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function billingAddressOf(array $data): array
    {
        $billingAddress = [];

        foreach (GuestCheckoutForm::INVOICE_FIELDS as $field) {
            $billingAddress[$field] = $data['invoice_'.$field] ?? null;
        }

        return $billingAddress;
    }

    private function renderIdentificationPageWithError(
        GuestCheckoutGate $guestCheckoutGate,
        BaseForm $form,
        string $message,
        bool $signInFirst = false,
    ): Response {
        $form->setErrorMessage($message);
        $this->getParserContext()->addForm($form);

        return $this->renderIdentificationPage($guestCheckoutGate, $signInFirst);
    }

    private function renderIdentificationPage(
        GuestCheckoutGate $guestCheckoutGate,
        bool $signInFirst = false,
    ): Response {
        return $this->render('checkout-identify', [
            'current' => CheckoutSteps::DELIVERY,
            'guest_checkout_offered' => $guestCheckoutGate->isOfferedForCurrentCart(),
            // Set when the address the visitor typed already has an account: the page then
            // opens on the login block instead of the form that cannot go through.
            'sign_in_first' => $signInFirst,
        ]);
    }
}
