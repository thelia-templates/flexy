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

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Form\Definition\FrontForm;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Log\Tlog;

#[Route('/account', name: 'account_')]
class AccountController extends BaseFrontController
{

  #[Route('', name: 'index')]
  public function noRouteAction(): Response
  {
    $this->checkAuth();

    return $this->render('account');
  }

  #[Route('/orders', name: 'account_orders')]
  public function ordersAction(): Response
  {
    $this->checkAuth();

    return $this->render('account-orders');
  }

  #[Route('/order/{orderId}', name: 'account_order', requirements: ['orderId' => '\d+'])]
  public function orderAction(int $orderId = null): Response
  {
    $this->checkAuth();

    return $this->render('account-order', [
      'orderId' => $orderId
    ]);
  }

  #[Route('/password', name: 'account_password', methods: 'POST')]
  public function passwordAction(EventDispatcherInterface $eventDispatcher)
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
}
