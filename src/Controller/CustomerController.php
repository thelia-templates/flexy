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

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Security\Exception\CustomerNotConfirmedException;
use Thelia\Core\Security\Exception\UsernameNotFoundException;
use Thelia\Core\Security\Exception\WrongPasswordException;
use Thelia\Core\Template\ParserContext;
use Thelia\Form\CustomerLogin;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Log\Tlog;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Event\CustomerEvent;
use Thelia\Service\Model\CustomerService;
use Thelia\Tools\RememberMeTrait;
use Thelia\Tools\URL;

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
        ParserContext $parserContext,
        CustomerService $customerService,
        EventDispatcherInterface $eventDispatcher,
    ) {
        if ($this->getSecurityContext()->hasCustomerUser()) {
            return new RedirectResponse(URL::getInstance()->absoluteUrl('/'));
        }

        /** @var CustomerLogin $customerLoginForm */
        $customerLoginForm = $this->createForm(CustomerLogin::class);

        try {
            $form = $this->validateForm($customerLoginForm, 'post');

            if ($form->get('account')->getData() == 0 && $form->get('email')->getErrors()->count() == 0) {
                return new RedirectResponse(URL::getInstance()->absoluteUrl('login'));
            }

            try {
                $customerService->login($customerLoginForm);

                return $this->generateSuccessRedirect($customerLoginForm);
            } catch (UsernameNotFoundException $e) {
                $message = $this->getTranslator()->trans('Wrong email or password. Please try again');
            } catch (WrongPasswordException $e) {
                $message = $this->getTranslator()->trans('Wrong email or password. Please try again');
            } catch (CustomerNotConfirmedException $e) {
                if ($e->getUser() !== null) {
                    // Send the confirmation email again
                    $eventDispatcher->dispatch(
                        new CustomerEvent($e->getUser()),
                        TheliaEvents::SEND_ACCOUNT_CONFIRMATION_EMAIL
                    );
                }
                $message = $this->getTranslator()->trans('Your account is not yet confirmed. A confirmation email has been sent to your email address, please check your mailbox');
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

        $parserContext->addForm($customerLoginForm);

        if ($customerLoginForm->hasErrorUrl()) {
            return $this->generateErrorRedirect($customerLoginForm);
        }
    }

    #[Route('/register', name: 'register', methods: ['GET'])]
    public function register(): Response
    {
        return $this->render('customer-register');
    }

    #[Route('/register', name: 'register_create', methods: ['POST'])]
    public function registerCreate(Session $session): RedirectResponse
    {
        $form = $this->createForm('flexybundle_form_customer_register_form');

        try {
            $this->validateForm($form, 'POST');

            $session->set('register_data', $form->getForm()->getData());

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $e) {
            $message = $this->translator->trans('Please check your input: %s', ['%s' => $e->getMessage()]);
        }

        Tlog::getInstance()->error(\sprintf('Error during address creation process : %s', $message));
        $form->setErrorMessage($message);

        $this->parserContext
            ->addForm($form)
            ->setGeneralError($message);

        return $this->generateErrorRedirect($form);
    }

    #[Route('/informations', name: 'informations', methods: ['GET'])]
    public function informations(Session $session): Response|RedirectResponse
    {
        $registerData = $session->get('register_data');

        if (null === $registerData) {
            $session->set('register_data', null);

            return new RedirectResponse(URL::getInstance()->absoluteUrl('/customer/register'));
        }

        return $this->render(
            'customer-informations',
            [
                'register_data' => $registerData,
            ]
        );
    }

    #[Route('/informations', name: 'informations_create', methods: ['POST'])]
    public function informationsCreate(CustomerService $customerService, Session $session): RedirectResponse
    {
        $form = $this->createForm('flexybundle_form_customer_informations_form');

        try {
            $this->validateForm($form, 'post');

            $newCustomer = $customerService->createCustomerMinimal($form->getForm());

            $session->set('register_data', null);

            if (!ConfigQuery::isCustomerEmailConfirmationEnable() && $newCustomer->getEnable()) {
                $customerService->processLogin($newCustomer);

                return new RedirectResponse(URL::getInstance()->absoluteUrl(''));
            }

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException|\Exception $e) {
            $message = $this->getTranslator()->trans('Please check your input: %s', ['%s' => $e->getMessage()]);
        }

        $session->set('register_data', null);

        Tlog::getInstance()->error(\sprintf('Error during address creation process : %s', $message));
        $form->setErrorMessage($message);

        $this->getParserContext()
            ->addForm($form)
            ->setGeneralError($message);

        return $this->generateErrorRedirect($form);
    }

    #[Route('/informations/update', name: 'information_update', methods: ['POST'])]
    public function updateInformations(CustomerService $customerService): RedirectResponse
    {
        $form = $this->createForm('flexybundle_form_customer_informations_form');

        try {
            $customerService->updateCustomerMinimal($form->getForm());

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $e) {
            $message = $this->getTranslator()->trans('Please check your input: %s', ['%s' => $e->getMessage()]);
        } catch (\Exception $e) {
            Tlog::getInstance()->error(\sprintf('Error : %s', $e->getMessage()));
            $message = $this->getTranslator()->trans('Critical error on customer informations update, check logs !');
        }

        $form->setErrorMessage($message);

        $this->getParserContext()
            ->addForm($form)
            ->setGeneralError($message);

        return $this->generateErrorRedirect($form);
    }

    #[Route('/activation', name: 'show_activation', methods: ['GET'])]
    public function showActivation(): Response
    {
        return $this->render('customer-activation');
    }

    #[Route('/logout', name: 'logout', methods: ['GET'])]
    public function logout(CustomerService $customerService): RedirectResponse
    {
        if (!$this->getSecurityContext()->hasCustomerUser()) {
            return new RedirectResponse(URL::getInstance()->absoluteUrl('/'));
        }

        $customerService->logout();

        return new RedirectResponse(URL::getInstance()->absoluteUrl('/'));
    }

    #[Route('/send-code', name: 'send_code', methods: ['GET'])]
    public function sendCode(): Response
    {
        dd('send code');
    }
}
