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
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
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
use Thelia\Log\Tlog;
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
    public function login(SessionInterface $session): Response
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
    ): ?Response {
        if ($this->getSecurityContext()->hasCustomerUser()) {
            return $this->generateRedirect('/');
        }

        $request = $this->getRequest();
        /** @var CustomerLogin $customerLoginForm */
        $customerLoginForm = $this->createForm(CustomerLogin::class);

        try {
            $form = $this->validateForm($customerLoginForm, 'post');

            if ((int) $form->get('account')->getData() === 0 && $form->get('email')->getErrors()->count() === 0) {
                return $this->generateRedirectFromRoute(
                    'customer.create.process',
                    ['email' => $form->get('email')->getData()]
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
            } catch (WrongPasswordException $e) {
                $message = $this->translator->trans(
                    'Wrong email or password. Please try again',
                    [],
                );
            } catch (CustomerNotConfirmedException $e) {
                if ($e->getUser() !== null) {
                    // Send the confirmation email again
                    $eventDispatcher->dispatch(
                        new CustomerEvent($e->getUser()),
                        TheliaEvents::SEND_ACCOUNT_CONFIRMATION_EMAIL
                    );
                }
                $message = $this->translator->trans(
                    'Your account is not yet confirmed. A confirmation email has been sent to your email address, please check your mailbox',
                    [],
                );
            }
        } catch (FormValidationException $e) {
            $message = $this->translator->trans(
                'Please check your input: %s',
                ['%s' => $e->getMessage()],
            );
        }

        Tlog::getInstance()->error(
            \sprintf(
                'Error during customer login process : %s. Exception was %s',
                $message,
                $e->getMessage()
            )
        );

        $customerLoginForm->setErrorMessage($message);

        $this->getParserContext()->addForm($customerLoginForm);

        if ($customerLoginForm->hasErrorUrl()) {
            return $this->generateErrorRedirect($customerLoginForm);
        }
        $this->addFlash('error', $message);

        return $this->generateRedirect(
            $this->generateUrl('customer_login')
        );
    }

    #[Route('/register', name: 'register', methods: ['GET'])]
    public function register(): Response
    {
        return $this->render('customer-register');
    }

    #[Route('/register', name: 'register_create', methods: ['POST'])]
    public function registerCreate(
        CustomerRegistrationService $customerRegistrationProcessor,
        SessionInterface $session,
    ): RedirectResponse {
        $form = $this->createForm(CustomerRegisterForm::class);

        try {
            $formValidated = $this->validateForm($form, Request::METHOD_POST);

            $customer = $customerRegistrationProcessor->registerCustomer(
                new CustomerRegisterDTO(
                    firstname: $formValidated->get('firstname')->getData(),
                    lastname: $formValidated->get('lastname')->getData(),
                    email: $formValidated->get('email')->getData(),
                    password: $formValidated->get('password')->getData()
                ));

            $session->set('registration_customer_id', $customer->getId());

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $e) {
            $message = $this->translator->trans('Please check your input: %s', ['%s' => $e->getMessage()]);
        }

        Tlog::getInstance()->error(\sprintf('Error during address creation process : %s', $message));
        $form->setErrorMessage($message);

        $this->parserContext
            ->addForm($form)
            ->setGeneralError($message);

        if ($form->hasErrorUrl()) {
            return $this->generateErrorRedirect($form);
        }

        return $this->generateRedirect(
            $this->generateUrl('customer_register')
        );
    }

    #[Route('/informations', name: 'informations', methods: ['GET'])]
    public function informations(
        SessionInterface $session,
    ): Response {
        $customer = $this->retrieveCustomerFromSession($session);

        $email = null;
        $firstname = null;
        $lastname = null;
        if ($customer instanceof Customer) {
            $email = $customer->getEmail();
            $firstname = $customer->getFirstname();
            $lastname = $customer->getLastname();
        } elseif ($this->getSecurityContext()->hasCustomerUser()) {
            $customer = $this->getSecurityContext()->getCustomerUser();
            $firstname = $customer->getFirstname();
            $lastname = $customer->getLastname();
        }

        return $this->render(
            'customer-informations',
            [
                'email' => $email,
                'firstname' => $firstname,
                'lastname' => $lastname,
            ]
        );
    }

    #[Route('/informations', name: 'informations_create', methods: ['POST'])]
    public function informationsCreate(
        CustomerCodeManager $customerCodeProcessor,
        AddressService $addressService,
        SessionInterface $session,
        NewsletterSubscriber $newsletterProcessor,
    ): RedirectResponse {
        $form = $this->createForm(CustomerInformationsForm::class);

        try {
            $formValidated = $this->validateForm($form, 'post');
            $customer = $this->retrieveCustomerFromSession($session);
            if (!$customer instanceof Customer) {
                return $this->generateRedirect(
                    $this->generateUrl('customer_register')
                );
            }

            if ($formValidated->get('accept_privacy_policy')->getData()) {
                $newsletterProcessor->subscribe($customer);
            }

            $addressService->createAddress($formValidated, $customer);

            if ($customer->getEnable()) {
                return $this->generateSuccessRedirect($form);
            }

            $customerCodeProcessor->createCodeAndSendIt($customer);

            return $this->generateRedirect(
                $this->generateUrl('customer_activation', ['email' => $customer->getEmail()])
            );
        } catch (FormValidationException $e) {
            $message = $this->translator->trans('Please check your input: %s', ['%s' => $e->getMessage()]);
        }

        Tlog::getInstance()->error(\sprintf('Error during address creation process : %s', $message));
        $form->setErrorMessage($message);

        $this->getParserContext()
            ->addForm($form)
            ->setGeneralError($message);

        if ($form->hasErrorUrl()) {
            return $this->generateErrorRedirect($form);
        }

        $this->addFlash('error', $this->translator->trans($message));

        return $this->generateRedirect(
            $this->generateUrl('customer_informations')
        );
    }

    #[Route(
        '/activation/{email}',
        name: 'activation',
        requirements: [
            'email' => '[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}',
        ],
        methods: ['GET']
    )]
    public function activation(
        string $email,
    ): Response {
        $customer = CustomerQuery::create()->findOneByEmail($email);
        if (!$customer instanceof Customer) {
            return $this->generateRedirect(
                $this->generateUrl('customer_register')
            );
        }

        return $this->render('customer-activation', [
            'email' => $email,
        ]);
    }

    /**
     * @throws PropelException
     */
    #[Route('/send-code/{email}', name: 'send_code', methods: ['GET'])]
    public function sendCode(
        string $email,
        CustomerCodeManager $customerCodeProcessor,
    ): Response {
        $customer = CustomerQuery::create()->findOneByEmail($email);
        if (!$customer instanceof Customer) {
            return $this->generateRedirect(
                $this->generateUrl('customer_register')
            );
        }

        $customerCodeProcessor->createCodeAndSendIt($customer);

        $this->addFlash(
            'information',
            $this->translator->trans(
                'A new activation code has been sent to your email address. Please check your mailbox. It\'s valid for 24 hours.'
            )
        );

        return $this->generateRedirect(
            $this->generateUrl('customer_activation', ['email' => $email])
        );
    }

    #[Route('/logout', name: 'logout', methods: ['GET'])]
    public function logout(EventDispatcherInterface $eventDispatcher): RedirectResponse
    {
        if ($this->getSecurityContext()->hasCustomerUser()) {
            $eventDispatcher->dispatch(new DefaultActionEvent(), TheliaEvents::CUSTOMER_LOGOUT);
        }

        $this->clearRememberMeCookie($this->getRememberMeCookieName());

        // Redirect to home page
        return $this->generateRedirect('/');
    }

    #[Route('/update', name: 'update', methods: ['POST'])]
    public function update(
        CustomerUpdateService $customerUpdateService,
    ): RedirectResponse {
        $form = $this->createForm(CustomerUpdateForm::class);

        try {
            $formValidated = $this->validateForm($form, Request::METHOD_POST);
            $customer = $this->getSecurityContext()->getCustomerUser();

            $customerUpdateService->updateCustomer(
                new CustomerRegisterDTO(
                    firstname: $formValidated->get('firstname')->getData(),
                    lastname: $formValidated->get('lastname')->getData(),
                    email: $formValidated->get('email')->getData()
                ),
                $customer
            );

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $e) {
            $message = $this->translator->trans('Please check your input: %s', ['%s' => $e->getMessage()]);
        }

        Tlog::getInstance()->error(\sprintf('Error during address creation process : %s', $message));
        $form->setErrorMessage($message);

        $this->parserContext
            ->addForm($form)
            ->setGeneralError($message);

        if ($form->hasErrorUrl()) {
            return $this->generateErrorRedirect($form);
        }

        return $this->generateRedirect(
            $this->generateUrl('customer_update')
        );
    }

    protected function getRememberMeCookieName()
    {
        return ConfigQuery::read('customer_remember_me_cookie_name', 'crmcn');
    }

    protected function getRememberMeCookieExpiration()
    {
        return ConfigQuery::read('customer_remember_me_cookie_expiration', 2592000 /* 1 month */);
    }

    protected function retrieveCustomerFromSession(SessionInterface $session): ?Customer
    {
        $customerId = $session->get('registration_customer_id');
        if ($customerId) {
            return CustomerQuery::create()->findPk($customerId);
        }

        return null;
    }
}
