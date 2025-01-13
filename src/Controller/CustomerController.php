<?php

namespace FlexyBundle\Controller;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Thelia\Action\RedirectException;
use Thelia\Core\Event\Customer\CustomerLoginEvent;
use Thelia\Core\Event\DefaultActionEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Security\Authentication\CustomerUsernamePasswordFormAuthenticator;
use Thelia\Core\Security\Exception\AuthenticationException;
use Thelia\Core\Security\Exception\CustomerNotConfirmedException;
use Thelia\Core\Security\Exception\UsernameNotFoundException;
use Thelia\Core\Security\Exception\WrongPasswordException;
use Thelia\Form\CustomerLogin;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Log\Tlog;
use Thelia\Model\Base\Customer;
use Thelia\Model\ConfigQuery;
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
  public function loginAction(EventDispatcherInterface $eventDispatcher)
  {

    if (!$this->getSecurityContext()->hasCustomerUser()) {
      $request = $this->getRequest();
      $customerLoginForm = $this->createForm(CustomerLogin::class);

      try {
        $form = $this->validateForm($customerLoginForm, 'post');

        // If User is a new customer
        if ($form->get('account')->getData() == 0 && $form->get('email')->getErrors()->count() == 0) {
          return $this->generateRedirectFromRoute(
            'customer.create.process',
            ['email' => $form->get('email')->getData()]
          );
        }
        try {
          $authenticator = new CustomerUsernamePasswordFormAuthenticator($request, $customerLoginForm);

          /** @var Customer $customer */
          $customer = $authenticator->getAuthentifiedUser();

          $this->processLogin($eventDispatcher, $customer);

          if ((int) $form->get('remember_me')->getData() > 0) {
            $this->createRememberMeCookie(
              $customer,
              $this->getRememberMeCookieName(),
              $this->getRememberMeCookieExpiration()
            );
          }

          return $this->generateSuccessRedirect($customerLoginForm);
        } catch (UsernameNotFoundException $e) {
          $message = $this->getTranslator()->trans(
            'Wrong email or password. Please try again',
            [],
          );
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
        } catch (AuthenticationException $e) {
          $message = $this->getTranslator()->trans(
            'Wrong email or password. Please try again',
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
        sprintf(
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
    }
  }
  #[Route('/register', name: 'register', methods: ['GET'])]
  public function register(): Response
  {
    return $this->render('customer-register');
  }

  #[Route('/register', name: 'register_create', methods: ['POST'])]
  public function registerCreate(SessionInterface $session): RedirectResponse
  {
    $form = $this->createForm('flexybundle_form_customer_register_form');

    try {
      $this->validateForm($form, 'POST');

      $session->set('register_data', $form->getForm()->getData());

      return $this->generateSuccessRedirect($form);
    } catch (FormValidationException $e) {
      $message = $this->translator->trans('Please check your input: %s', ['%s' => $e->getMessage()]);
    }

    Tlog::getInstance()->error(sprintf('Error during address creation process : %s', $message));
    $form->setErrorMessage($message);

    $this->parserContext
      ->addForm($form)
      ->setGeneralError($message);

    if ($form->hasErrorUrl()) {
      return $this->generateErrorRedirect($form);
    }
  }

  #[Route('/informations', name: 'informations', methods: ['GET'])]
  public function informations(SessionInterface $session): Response
  {
    $registerData = $session->get('register_data');

    if (null === $registerData) {
      throw new RedirectException($this->generateUrl('customer_index'));
    }

    return $this->render(
      'customer-informations',
      [
        'register_data' => $registerData
      ]
    );
  }

  #[Route('/informations', name: 'informations_create', methods: ['POST'])]
  public function informationsCreate(EventDispatcherInterface $eventDispatcher): RedirectResponse
  {
    $form = $this->createForm('flexybundle_form_customer_informations_form');

    try {
      $this->validateForm($form, 'post');

      // TODO : Ajouter le customer en base sans adresse

      return $this->generateSuccessRedirect($form);
    } catch (FormValidationException $e) {
      $message = $this->getTranslator()->trans('Please check your input: %s', ['%s' => $e->getMessage()]);
    }

    Tlog::getInstance()->error(sprintf('Error during address creation process : %s', $message));
    $form->setErrorMessage($message);

    $this->getParserContext()
      ->addForm($form)
      ->setGeneralError($message);

    if ($form->hasErrorUrl()) {
      return $this->generateErrorRedirect($form);
    }
  }

  #[Route('/activation', name: 'activation', methods: ['GET'])]
  public function activation(): Response
  {
    return $this->render('customer-activation');
  }

  protected function processLogin(EventDispatcherInterface $eventDispatcher, Customer $customer): void
  {
    $eventDispatcher->dispatch(new CustomerLoginEvent($customer), TheliaEvents::CUSTOMER_LOGIN);
  }

  #[Route('/logout', name: 'logout', methods: ['GET'])]
  public function logout(EventDispatcherInterface $eventDispatcher)
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
}
