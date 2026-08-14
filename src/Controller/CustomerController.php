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

use FlexyBundle\Form\CustomerInformationsForm;
use FlexyBundle\Form\CustomerRegisterForm;
use FlexyBundle\Form\CustomerUpdateForm;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Thelia\Core\Event\DefaultActionEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\Authentication\CustomerUsernamePasswordFormAuthenticator;
use Thelia\Core\Security\Exception\CustomerNotConfirmedException;
use Thelia\Core\Security\Exception\WrongPasswordException;
use Thelia\Domain\Addressing\Service\AddressService;
use Thelia\Domain\Customer\DTO\CustomerRegisterDTO;
use Thelia\Domain\Customer\Service\CustomerAuthenticator;
use Thelia\Domain\Customer\Service\CustomerCodeManager;
use Thelia\Domain\Customer\Service\CustomerRegistrationService;
use Thelia\Domain\Customer\Service\CustomerUpdateService;
use Thelia\Domain\Marketing\Service\NewsletterSubscriber;
use Thelia\Form\CustomerLogin;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Event\CustomerEvent;
use Thelia\Tools\RememberMeTrait;

#[Route('/customer', name: 'customer_')]
class CustomerController extends FlexyController
{
    use RememberMeTrait;

    #[Route('/login', name: 'login', methods: ['GET'])]
    public function login(): Response
    {
        if ($this->securityService->isAuthenticatedFront()) {
            return $this->generateRedirect('/account');
        }

        return $this->render('login');
    }

    #[Route('/login', name: 'login_action', methods: ['POST'])]
    public function loginAction(
        EventDispatcherInterface $eventDispatcher,
        CustomerAuthenticator $customerLoginProcessor,
        SessionInterface $session,
    ): ?Response {
        if ($this->getSecurityContext()->hasCustomerUser()) {
            return $this->generateRedirect('/');
        }

        $request = $this->getRequest();
        /** @var CustomerLogin $customerLoginForm */
        $customerLoginForm = $this->createForm(CustomerLogin::class);
        $message = null;

        try {
            $form = $this->validateForm($customerLoginForm, 'post');

            if (0 === (int) $form->get('account')->getData() && 0 === $form->get('email')->getErrors()->count()) {
                return $this->generateRedirect(
                    $this->generateUrl('customer_register', ['email' => $form->get('email')->getData()])
                );
            }

            try {
                $authenticator = new CustomerUsernamePasswordFormAuthenticator($request, $customerLoginForm);

                /** @var Customer $customer */
                $customer = $authenticator->getAuthentifiedUser();

                $customerLoginProcessor->processLogin($customer);

                if ((int) $form->get('remember_me')->getData() > 0) {
                    $this->createRememberMeCookie(
                        $customer,
                        $this->getRememberMeCookieName(),
                        $this->getRememberMeCookieExpiration()
                    );
                }

                return $this->generateSuccessRedirect($customerLoginForm);
            } catch (UserNotFoundException|WrongPasswordException) {
                // Both cases must be indistinguishable: a message specific to an unknown
                // email would tell an attacker which addresses have an account here.
                $message = $this->getTranslator()->trans('Wrong email or password. Please try again');
            } catch (CustomerNotConfirmedException $e) {
                // The password has been checked before this point, so the visitor owns
                // the account: put it back in the activation flow the registration uses,
                // send a fresh code and take them to the page that asks for it. Saying
                // "check your mailbox" on the login page left them with no way in, since
                // registering again with the same address is refused.
                $customer = $e->getUser();

                if ($customer instanceof Customer) {
                    $session->set('registration_customer_id', $customer->getId());

                    $eventDispatcher->dispatch(
                        new CustomerEvent($customer),
                        TheliaEvents::SEND_ACCOUNT_CONFIRMATION_EMAIL
                    );

                    $this->addFlash(
                        'information',
                        $this->translator->trans('Your account is not yet confirmed. A new activation code has been sent to your email address.')
                    );

                    return $this->generateRedirect($this->generateUrl('customer_activation'));
                }

                $message = $this->translator->trans(
                    'Your account is not yet confirmed. A confirmation email has been sent to your email address, please check your mailbox'
                );
            }
        } catch (FormValidationException $e) {
            $message = $this->getTranslator()->trans('Please check your input: %s', ['%s' => $e->getMessage()]);
        }

        $customerLoginForm->setErrorMessage($message);
        $this->getParserContext()->addForm($customerLoginForm);

        if ($customerLoginForm->hasErrorUrl()) {
            return $this->generateErrorRedirect($customerLoginForm);
        }

        return $this->generateRedirect($this->generateUrl('customer_login'));
    }

    #[Route('/register', name: 'register', methods: ['GET'])]
    public function register(): Response
    {
        // Without this, a logged-in visitor creates a second, orphaned Customer row and the
        // informations step then writes onto their original account.
        if ($this->securityService->isAuthenticatedFront()) {
            return $this->generateRedirect('/account');
        }

        return $this->render('register');
    }

    #[Route('/register', name: 'register_create', methods: ['POST'])]
    public function registerCreate(
        CustomerRegistrationService $customerRegistrationProcessor,
        SessionInterface $session,
    ): RedirectResponse {
        if ($this->securityService->isAuthenticatedFront()) {
            return $this->generateRedirect('/account');
        }

        $form = $this->createForm(CustomerRegisterForm::class);

        try {
            $formValidated = $this->validateForm($form, Request::METHOD_POST);

            $customer = $customerRegistrationProcessor->registerCustomer(new CustomerRegisterDTO(
                firstname: $formValidated->get('firstname')->getData(),
                lastname: $formValidated->get('lastname')->getData(),
                email: $formValidated->get('email')->getData(),
                password: $formValidated->get('password')->getData()
            ));

            $session->set('registration_customer_id', $customer->getId());

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $e) {
            $message = $this->getTranslator()->trans('Please check your input: %s', ['%s' => $e->getMessage()]);
        }

        $form->setErrorMessage($message);
        $this->getParserContext()->addForm($form);

        if ($form->hasErrorUrl()) {
            return $this->generateErrorRedirect($form);
        }

        return $this->generateRedirect($this->generateUrl('customer_register'));
    }

    #[Route('/informations', name: 'informations', methods: ['GET'])]
    public function informations(SessionInterface $session): Response
    {
        $customer = $this->retrieveCustomerFromSession($session);

        // Second registration step: it completes the account the first step created, and the
        // activation link it builds needs that account. Reaching it without one means the
        // registration has to start over — a logged-in visitor always has a customer here,
        // since that is the first thing retrieveCustomerFromSession() returns.
        if (null === $customer) {
            return $this->generateRedirect($this->generateUrl('customer_register'));
        }

        return $this->render('customer-informations', [
            'firstname' => $customer->getFirstname(),
            'lastname' => $customer->getLastname(),
        ]);
    }

    #[Route('/informations', name: 'informations_create', methods: ['POST'])]
    public function informationsCreate(
        AddressService $addressService,
        SessionInterface $session,
        NewsletterSubscriber $newsletterProcessor,
    ): RedirectResponse {
        $form = $this->createForm(CustomerInformationsForm::class);

        try {
            $formValidated = $this->validateForm($form, 'post');
            $customer = $this->retrieveCustomerFromSession($session);

            if (!$customer instanceof Customer) {
                return $this->generateRedirect($this->generateUrl('customer_register'));
            }

            if ($formValidated->get('newsletter')->getData()) {
                $newsletterProcessor->subscribe($customer);
            }

            $addressService->createAddress($formValidated, $customer);

            if ($customer->getEnable()) {
                return $this->generateSuccessRedirect($form);
            }

            // No code is sent here: the account creation of the previous step already
            // mailed one. Sending a second one would invalidate the code the visitor
            // received first, on top of mailing them twice for one registration.
            return $this->generateRedirect($this->generateUrl('customer_activation'));
        } catch (FormValidationException $e) {
            $message = $this->getTranslator()->trans('Please check your input: %s', ['%s' => $e->getMessage()]);
        }

        $form->setErrorMessage($message);
        $this->getParserContext()->addForm($form);

        if ($form->hasErrorUrl()) {
            return $this->generateErrorRedirect($form);
        }

        return $this->generateRedirect($this->generateUrl('customer_informations'));
    }

    // The address being activated comes from the visitor's own session, never from the url:
    // naming it in the url made these two pages answer differently for an address that has
    // an account and for one that does not, which is enough to test whether someone is a
    // customer of the shop, without logging in, one request at a time.
    #[Route('/activation', name: 'activation', methods: ['GET'])]
    public function activation(SessionInterface $session): Response
    {
        $customer = $this->retrievePendingCustomer($session);

        if (!$customer instanceof Customer) {
            // Read through getCustomerUser() rather than hasCustomerUser(): the latter is
            // annotated `@return true` in the core, so PHPStan narrows every call to it.
            return $this->generateRedirect(
                $this->getSecurityContext()->getCustomerUser() instanceof Customer
                    ? $this->generateUrl('account_index')
                    : $this->generateUrl('customer_register')
            );
        }

        return $this->render('customer-activation');
    }

    #[Route('/send-code', name: 'send_code', methods: ['GET'])]
    public function sendCode(CustomerCodeManager $customerCodeProcessor, SessionInterface $session): RedirectResponse
    {
        $customer = $this->retrievePendingCustomer($session);

        if (!$customer instanceof Customer) {
            return $this->generateRedirect($this->generateUrl('customer_register'));
        }

        // Rate limited in the core: past a few requests per address and per client, nothing
        // is sent. The page below says the same thing either way, so a caller cannot use the
        // answer to tell whether a mail actually went out.
        $customerCodeProcessor->requestCode($customer);

        $this->addFlash(
            'information',
            $this->translator->trans('A new activation code has been sent to your email address. Please check your mailbox.')
        );

        return $this->generateRedirect($this->generateUrl('customer_activation'));
    }

    #[Route('/logout', name: 'logout', methods: ['GET'])]
    public function logout(EventDispatcherInterface $eventDispatcher): RedirectResponse
    {
        if ($this->getSecurityContext()->hasCustomerUser()) {
            $eventDispatcher->dispatch(new DefaultActionEvent(), TheliaEvents::CUSTOMER_LOGOUT);
        }

        $this->clearRememberMeCookie($this->getRememberMeCookieName());

        return $this->generateRedirect('/');
    }

    #[Route('/update', name: 'update', methods: ['POST'])]
    public function update(CustomerUpdateService $customerUpdateService): RedirectResponse
    {
        $this->checkAuth();

        $customer = $this->getSecurityContext()->getCustomerUser();

        // The email field is read-only unless the shop allows email changes, and Symfony
        // ignores what a disabled field submits. Seeding the form with the current values
        // keeps the address visible when a validation error sends the form back.
        $form = $this->createForm(CustomerUpdateForm::FORM_NAME, data: [
            'firstname' => $customer->getFirstname(),
            'lastname' => $customer->getLastname(),
            'email' => $customer->getEmail(),
        ]);

        try {
            $validatedForm = $this->validateForm($form, Request::METHOD_POST);

            $customerUpdateService->updateCustomer(
                new CustomerRegisterDTO(
                    firstname: $validatedForm->get('firstname')->getData(),
                    lastname: $validatedForm->get('lastname')->getData(),
                    email: $validatedForm->get('email')->getData(),
                ),
                $customer,
            );

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $e) {
            $message = $this->translator->trans('Please check your input: %s', ['%s' => $e->getMessage()]);
        }

        $this->logger->error(\sprintf('Error during customer profile update process: %s.', $message));

        $form->setErrorMessage($message);
        $this->parserContext
            ->addForm($form)
            ->setGeneralError($message)
        ;

        if ($form->hasErrorUrl()) {
            return $this->generateErrorRedirect($form);
        }

        return $this->generateRedirectFromRoute('account_index');
    }

    protected function getRememberMeCookieName(): string
    {
        return ConfigQuery::read('customer_remember_me_cookie_name', 'crmcn');
    }

    protected function getRememberMeCookieExpiration(): int
    {
        return (int) ConfigQuery::read('customer_remember_me_cookie_expiration', 2592000);
    }

    // Deliberately narrower than retrieveCustomerFromSession(): the activation flow is about
    // the pending registration alone. A logged-in visitor cannot be unconfirmed — login raises
    // CustomerNotConfirmedException — so resolving the current user here would only serve to
    // render an activation page to someone who has no code to enter.
    private function retrievePendingCustomer(SessionInterface $session): ?Customer
    {
        $customerId = $session->get('registration_customer_id');

        return $customerId ? CustomerQuery::create()->findPk($customerId) : null;
    }

    protected function retrieveCustomerFromSession(SessionInterface $session): ?Customer
    {
        if ($this->getSecurityContext()->hasCustomerUser()) {
            return $this->getSecurityContext()->getCustomerUser();
        }

        $customerId = $session->get('registration_customer_id');

        return $customerId ? CustomerQuery::create()->findPk($customerId) : null;
    }
}
