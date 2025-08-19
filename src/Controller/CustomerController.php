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
use FlexyBundle\Service\Customer\CustomerCodeProcessor;
use FlexyBundle\Service\Customer\CustomerLoginProcessor;
use FlexyBundle\Service\Customer\CustomerRegistrationProcessor;
use FlexyBundle\Service\Newsletter\NewsletterProcessor;
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

    #[Route('', name: 'index', methods: ['GET'])]
    public function noRoute(): Response
    {
        return $this->render('customer-register');
    }

    #[Route('/login', name: 'login', methods: ['GET'])]
    public function login(): Response
    {
        return $this->render('login');
    }

    #[Route('/login', name: 'login_action', methods: ['POST'])]
    public function loginAction(
        EventDispatcherInterface $eventDispatcher,
        CustomerLoginProcessor $customerLoginProcessor,
    ): ?Response
    {
        if ($this->getSecurityContext()->hasCustomerUser()) {
            return $this->generateRedirectFromRoute('index');
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
                $message = $this->getTranslator()->trans(
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
                $message = $this->getTranslator()->trans(
                    'Your account is not yet confirmed. A confirmation email has been sent to your email address, please check your mailbox',
                    [],
                );
            }
        } catch (FormValidationException $e) {
            $message = $this->getTranslator()->trans(
                'Please check your input: %s',
                ['%s' => $e->getMessage()],
            );
        } catch (\Exception $e) {
            $message = $this->getTranslator()->trans(
                'Sorry, an error occured: %s',
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
        CustomerRegistrationProcessor $customerRegistrationProcessor,
        SessionInterface $session,
    ): RedirectResponse {
        $form = $this->createForm(CustomerRegisterForm::class);

        try {
            $formValidated = $this->validateForm($form, Request::METHOD_POST);

            $customer = $customerRegistrationProcessor->registerCustomer([
                'firstName' => 'TMP',
                'lastName' => 'TMP',
                'email' => $formValidated->get('email')->getData(),
                'password' => $formValidated->get('password')->getData(),
            ]);

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
        if ($customer instanceof Customer) {
            $email = $customer->getEmail();
        } elseif ($this->getSecurityContext()->hasCustomerUser()) {
            $customer = $this->getSecurityContext()->getCustomerUser();
            $email = $customer->getEmail();
        }

        return $this->render(
            'customer-informations',
            [
                'email' => $email,
            ]
        );
    }

    #[Route('/informations', name: 'informations_create', methods: ['POST'])]
    public function informationsCreate(
        CustomerCodeProcessor $customerCodeProcessor,
        SessionInterface $session,
        NewsletterProcessor $newsletterProcessor,
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
                $newsletterProcessor->subscribeToNewsletter($customer);
            }

            $customer
                ->setFirstname($formValidated->get('firstname')->getData())
                ->setLastname($formValidated->get('lastname')->getData())
                ->save();

            $customerCodeProcessor->createCodeAndSendIt($customer);

            return $this->generateRedirect(
                $this->generateUrl('customer_activation', ['email' => $customer->getEmail()])
            );
        } catch (FormValidationException $e) {
            $message = $this->getTranslator()->trans('Please check your input: %s', ['%s' => $e->getMessage()]);
        }

        Tlog::getInstance()->error(\sprintf('Error during address creation process : %s', $message));
        $form->setErrorMessage($message);

        $this->getParserContext()
          ->addForm($form)
          ->setGeneralError($message);

        if ($form->hasErrorUrl()) {
            return $this->generateErrorRedirect($form);
        }

        $this->addFlash('error', $this->getTranslator()->trans($message));

        return $this->generateRedirect(
            $this->generateUrl('customer_informations')
        );
    }

    #[Route(
        '/activation/{email}',
        name: 'activation',
        requirements: [
            'email' => '[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}'
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
        CustomerCodeProcessor $customerCodeProcessor,
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
        return $this->generateRedirect($this->generateUrl('index'));
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
