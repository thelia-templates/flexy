<?php

namespace FlexyBundle\Controller;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Thelia\Action\RedirectException;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Log\Tlog;

#[Route('/customer', name: 'customer_')]

class CustomerController extends FlexyController
{
  #[Route('', name: 'index', methods: ['GET'])]
  public function noRoute(): Response
  {
    return $this->render('customer-register');
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

  #[Route('/password/forgotten', name: 'password_forgotten', methods: ['GET'])]
  public function password(): Response
  {
    return $this->render('customer-password-forgotten');
  }
}
