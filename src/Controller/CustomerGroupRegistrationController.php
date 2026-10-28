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

use FlexyBundle\Form\CustomerRegisterForm;
use FlexyBundle\Service\GuestCheckoutGate;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Domain\Customer\DTO\CustomerRegisterDTO;
use Thelia\Domain\Customer\Exception\InvalidRegistrationTokenException;
use Thelia\Domain\Customer\Service\AuthenticationReturnUrl;
use Thelia\Domain\Customer\Service\CustomerAuthenticator;
use Thelia\Domain\Customer\Service\CustomerGroupRegistration;
use Thelia\Domain\Customer\Service\CustomerRegistrationService;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Form\Exception\FormValidationException;

/**
 * The sign-up form a customer group hands out through a secret link: the standard
 * registration form, whose new account joins the group the link opens.
 *
 * The token is the one of the URL, read again at every request and checked against the
 * database before anything is shown or written: the form carries no group and no token of
 * its own, so nothing the visitor posts can name a group. A token that opens nothing (never
 * issued, regenerated, closed, malformed) answers like any page that does not exist.
 */
#[Route('/register/{token}', requirements: ['token' => '[^/]+'])]
class CustomerGroupRegistrationController extends FlexyController
{
    #[Route('', name: 'customer_group_register', methods: ['GET'])]
    public function register(
        string $token,
        CustomerGroupRegistration $customerGroupRegistration,
        AuthenticationReturnUrl $authenticationReturnUrl,
        SessionInterface $session,
    ): Response {
        $this->requireAnOpenLink($token, $customerGroupRegistration);

        $authenticationReturnUrl->capture();

        // The same detour as the standard form: an account is already signed in, or a
        // registration is under way and its next step is the one to go back to.
        if ($this->getSecurityContext()->hasAuthenticatedCustomerUser()) {
            return $this->generateRedirect($this->generateUrl(
                null !== $session->get('registration_customer_id') ? 'customer_informations' : 'account_index'
            ));
        }

        return $this->render('register', [
            'returnUrl' => $authenticationReturnUrl->requested(),
            'registrationToken' => $token,
        ]);
    }

    #[Route('', name: 'customer_group_register_create', methods: ['POST'])]
    public function registerCreate(
        string $token,
        CustomerGroupRegistration $customerGroupRegistration,
        CustomerRegistrationService $customerRegistrationProcessor,
        SessionInterface $session,
        LangService $langService,
        CustomerAuthenticator $customerLoginProcessor,
        GuestCheckoutGate $guestCheckoutGate,
        AuthenticationReturnUrl $authenticationReturnUrl,
    ): RedirectResponse {
        if ($this->getSecurityContext()->hasAuthenticatedCustomerUser()) {
            return $this->generateRedirect('/account');
        }

        // Before the form is even read: a link that no longer opens a group creates nothing.
        $this->requireAnOpenLink($token, $customerGroupRegistration);

        $form = $this->createForm(CustomerRegisterForm::class);

        try {
            $formValidated = $this->validateForm($form, Request::METHOD_POST);

            try {
                $customer = $customerRegistrationProcessor->registerCustomer(new CustomerRegisterDTO(
                    firstname: $formValidated->get('firstname')->getData(),
                    lastname: $formValidated->get('lastname')->getData(),
                    email: $formValidated->get('email')->getData(),
                    password: $formValidated->get('password')->getData(),
                    langId: $langService->getLang()?->getId(),
                ), $token);
            } catch (InvalidRegistrationTokenException) {
                // The link was closed between the check above and the write.
                throw new NotFoundHttpException();
            }

            $session->set('registration_customer_id', $customer->getId());
            $authenticationReturnUrl->capture();

            if ($customer->getEnable()) {
                $customerLoginProcessor->processLogin($customer);
                $guestCheckoutGate->markAsAuthenticated();
            }

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $e) {
            $message = $this->getTranslator()->trans('Please check your input: %s', ['%s' => $e->getMessage()]);
        }

        $form->setErrorMessage($message);
        $this->getParserContext()->addForm($form);

        if ($form->hasErrorUrl()) {
            return $this->generateErrorRedirect($form);
        }

        return $this->generateRedirect($this->generateUrl('customer_group_register', ['token' => $token]));
    }

    private function requireAnOpenLink(string $token, CustomerGroupRegistration $customerGroupRegistration): void
    {
        if (null === $customerGroupRegistration->groupForToken($token)) {
            throw new NotFoundHttpException();
        }
    }
}
