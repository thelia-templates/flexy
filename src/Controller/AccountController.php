<?php

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

use Front\Front;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Form\Definition\FrontForm;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Log\Tlog;
use Thelia\Core\Event\Address\AddressCreateOrUpdateEvent;
use Symfony\Component\Form\Form;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Model\Base\AddressQuery;
use Thelia\Model\Event\AddressEvent;

#[Route('/account', name: 'account_')]
class AccountController extends BaseFrontController
{

  private UrlGeneratorInterface $urlGenerator;

  public function __construct(UrlGeneratorInterface $urlGenerator)
  {
    $this->urlGenerator = $urlGenerator;
  }

  #[Route('', name: 'index')]
  public function noRoute(): Response
  {
    $this->checkAuth();

    return $this->render('account');
  }

  #[Route('/addresses', name: 'addresses')]
  public function addresses(): Response
  {
    $this->checkAuth();

    return $this->render('account-addresses');
  }

  #[Route('/address/{addressId}', name: 'address_update', requirements: ['addressId' => '\d+'])]
  public function address(int $addressId = null): Response
  {
    $this->checkAuth();

    return $this->render('address-update', [
      'addressId' => $addressId
    ]);
  }

  #[Route('/address', name: 'address', methods: ['GET'])]
  public function addressNew(): Response
  {
    $this->checkAuth();

    return $this->render('address');
  }


  #[Route('/address', name: 'address_create', methods: ['POST'])]
  public function addressCreate(EventDispatcherInterface $eventDispatcher): RedirectResponse
  {
    $this->checkAuth();

    $addressCreate = $this->createForm(FrontForm::ADDRESS_CREATE);

    try {
      /** @var Customer $customer */
      $customer = $this->getSecurityContext()->getCustomerUser();

      $form = $this->validateForm($addressCreate, 'post');
      $event = $this->createAddressEvent($form);
      $event->setCustomer($customer);

      $eventDispatcher->dispatch($event, TheliaEvents::ADDRESS_CREATE);

      return $this->generateSuccessRedirect($addressCreate);
    } catch (FormValidationException $e) {
      $message = $this->getTranslator()->trans('Please check your input: %s', ['%s' => $e->getMessage()], Front::MESSAGE_DOMAIN);
    } catch (\Exception $e) {
      $message = $this->getTranslator()->trans('Sorry, an error occured: %s', ['%s' => $e->getMessage()], Front::MESSAGE_DOMAIN);
    }

    Tlog::getInstance()->error(sprintf('Error during address creation process : %s', $message));

    $addressCreate->setErrorMessage($message);

    $this->getParserContext()
      ->addForm($addressCreate)
      ->setGeneralError($message)
    ;

    // Redirect to error URL if defined
    if ($addressCreate->hasErrorUrl()) {
      return $this->generateErrorRedirect($addressCreate);
    }
  }

  #[Route('/address/delete/{addressId}', name: 'address_delete', requirements: ['addressId' => '\d+'])]
  public function addressDelete(EventDispatcherInterface $eventDispatcher, int $addressId = null): RedirectResponse
  {
    $this->checkAuth();
    $error_message = false;

    $customer = $this->getSecurityContext()->getCustomerUser();
    $address = AddressQuery::create()->findPk($addressId);

    if (!$address || $customer->getId() != $address->getCustomerId()) {
      // If Ajax Request
      if ($this->getRequest()->isXmlHttpRequest()) {
        return $this->jsonResponse(
          json_encode(
            [
              'success' => false,
              'message' => $this->getTranslator()->trans(
                'Error during address deletion process',
                [],
                Front::MESSAGE_DOMAIN
              ),
            ]
          )
        );
      }

      $url = $this->urlGenerator->generate('account_addresses', [
        'delete_success' => 1
      ]);
      return new RedirectResponse($url);
    }

    try {
      $eventDispatcher->dispatch(new AddressEvent($address), TheliaEvents::ADDRESS_DELETE);
    } catch (\Exception $e) {
      $error_message = $e->getMessage();
    }

    Tlog::getInstance()->error(sprintf('Error during address deletion : %s', $error_message));

    // If Ajax Request
    if ($this->getRequest()->isXmlHttpRequest()) {
      if ($error_message) {
        $response = $this->jsonResponse(json_encode([
          'success' => false,
          'message' => $error_message,
        ]));
      } else {
        $response = $this->jsonResponse(
          json_encode([
            'success' => true,
            'message' => '',
          ])
        );
      }

      return $response;
    }
    $url = $this->urlGenerator->generate('account_addresses', [
      'delete_success' => 1
    ]);
    return new RedirectResponse($url);
  }

  #[Route('/address/default/{addressId}', name: 'address_default', requirements: ['addressId' => '\d+'])]
  public function addressDefault(EventDispatcherInterface $eventDispatcher, int $addressId = null): RedirectResponse
  {
    $this->checkAuth();

    $address = AddressQuery::create()
      ->filterByCustomerId($this->getSecurityContext()->getCustomerUser()->getId())
      ->findPk($addressId);

    if (null === $address) {
      $url = $this->urlGenerator->generate('account_addresses', [
        'error' => true
      ]);
    }

    try {
      $event = new AddressEvent($address);
      $eventDispatcher->dispatch($event, TheliaEvents::ADDRESS_DEFAULT);

      $url = $this->urlGenerator->generate('account_addresses');

      $url = $this->urlGenerator->generate('account_addresses');
    } catch (\Exception $e) {
      $this->getParserContext()
        ->setGeneralError($e->getMessage())
      ;
      $url = $this->urlGenerator->generate('account_addresses', [
        'error' => true
      ]);
    }
    return new RedirectResponse($url);
  }

  #[Route('/orders', name: 'orders')]
  public function orders(): Response
  {
    $this->checkAuth();

    return $this->render('account-orders');
  }

  #[Route('/order/{orderId}', name: 'order', requirements: ['orderId' => '\d+'])]
  public function order(int $orderId = null): Response
  {
    $this->checkAuth();

    return $this->render('account-order', [
      'orderId' => $orderId
    ]);
  }

  #[Route('/password', name: 'password', methods: 'POST')]
  public function password(EventDispatcherInterface $eventDispatcher)
  {
    if ($this->getSecurityContext()->hasCustomerUser()) {
      $customerPasswordUpdateForm = $this->createForm(FrontForm::CUSTOMER_PASSWORD_UPDATE);
      try {
        /** @var Customer $customer */
        $customer = $this->getSecurityContext()->getCustomerUser();

        $form = $this->validateForm($customerPasswordUpdateForm, 'post');

        $customerChangeEvent = $this->createEventInstance($form->getData());
        $customerChangeEvent->setCustomer($customer);
        $eventDispatcher->dispatch($customerChangeEvent, TheliaEvents::CUSTOMER_UPDATEPROFILE);

        return $this->generateSuccessRedirect($customerPasswordUpdateForm);
      } catch (FormValidationException $e) {
        $message = $this->getTranslator()->trans(
          'Please check your input: %s',
          [
            '%s' => $e->getMessage(),
          ],
        );
      } catch (\Exception $e) {
        $message = $this->getTranslator()->trans(
          'Sorry, an error occured: %s',
          [
            '%s' => $e->getMessage(),
          ],
        );
      }

      Tlog::getInstance()->error(
        sprintf(
          'Error during customer password modification process : %s.',
          $message
        )
      );

      $customerPasswordUpdateForm->setErrorMessage($message);

      $this->getParserContext()
        ->addForm($customerPasswordUpdateForm)
        ->setGeneralError($message)
      ;

      // Redirect to error URL if defined
      if ($customerPasswordUpdateForm->hasErrorUrl()) {
        return $this->generateErrorRedirect($customerPasswordUpdateForm);
      }
    }
  }


  /*
 * Ci-dessous, toutes les méthodes qui devraient être migrer dans un service, dans le core.
 * Elles proviennent toutes du module Front
 */



  /**
   * @return \Thelia\Core\Event\Customer\CustomerCreateOrUpdateEvent
   */
  private function createEventInstance($data)
  {
    $customerCreateEvent = new CustomerCreateOrUpdateEvent(
      $data['title'] ?? null,
      $data['firstname'] ?? null,
      $data['lastname'] ?? null,
      $data['address1'] ?? null,
      $data['address2'] ?? null,
      $data['address3'] ?? null,
      $data['phone'] ?? null,
      $data['cellphone'] ?? null,
      $data['zipcode'] ?? null,
      $data['city'] ?? null,
      $data['country'] ?? null,
      $data['email'] ?? null,
      $data['password'] ?? null,
      $data['lang_id'] ?? $this->getSession()->getLang()->getId(),
      $data['reseller'] ?? null,
      $data['sponsor'] ?? null,
      $data['discount'] ?? null,
      $data['company'] ?? null,
      null,
      $data['state'] ?? null
    );

    return $customerCreateEvent;
  }

  protected function createAddressEvent(Form $form)
  {
    return new AddressCreateOrUpdateEvent(
      $form->get('label')->getData(),
      $form->get('title')->getData(),
      $form->get('firstname')->getData(),
      $form->get('lastname')->getData(),
      $form->get('address1')->getData(),
      $form->get('address2')->getData(),
      $form->get('address3')->getData(),
      $form->get('zipcode')->getData(),
      $form->get('city')->getData(),
      $form->get('country')->getData(),
      $form->get('cellphone')->getData(),
      $form->get('phone')->getData(),
      $form->get('company')->getData(),
      $form->get('is_default')->getData(),
      $form->get('state')->getData()
    );
  }
}
