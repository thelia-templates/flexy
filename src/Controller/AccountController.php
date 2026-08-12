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

use FlexyBundle\Form\AddressEditForm;
use Front\Front;
use Symfony\Component\Form\Form;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Address\AddressCreateOrUpdateEvent;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateEvent;
use Thelia\Core\Event\Customer\CustomerPersonalDataExportEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Addressing\Service\AddressService;
use Thelia\Form\Definition\FrontForm;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\Address;
use Thelia\Model\AddressQuery;
use Thelia\Model\Customer;
use Thelia\Model\Event\AddressEvent;

#[Route('/account', name: 'account_')]
class AccountController extends FlexyController
{
    public const DELETE_ADDRESS_TOKEN_ID = 'delete_address';
    public const DEFAULT_ADDRESS_TOKEN_ID = 'default_address';
    public const PERSONAL_DATA_TOKEN_ID = 'personal_data';

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

    #[Route('/address/{addressId}', name: 'address', requirements: ['addressId' => '\d+'], methods: ['GET'])]
    public function address(?int $addressId = null): Response
    {
        $this->checkAuth();

        if (null === $this->findCustomerAddress($addressId)) {
            return new RedirectResponse($this->generateUrl('account_addresses', [
                'error' => true,
            ]));
        }

        return $this->render('address-update', [
            'addressId' => $addressId,
        ]);
    }

    #[Route('/address/{addressId}', name: 'address_update', requirements: ['addressId' => '\d+'], methods: ['POST'])]
    public function addressUpdate(EventDispatcherInterface $eventDispatcher, ?int $addressId = null): RedirectResponse
    {
        $this->checkAuth();

        $addressUpdate = $this->createForm(AddressEditForm::FORM_NAME);

        try {
            $form = $this->validateForm($addressUpdate);

            $address = $this->findCustomerAddress($addressId);

            if (null === $address) {
                return $this->generateRedirectFromRoute('default');
            }

            $event = $this->createAddressEvent($form);
            $event->setAddress($address);

            $eventDispatcher->dispatch($event, TheliaEvents::ADDRESS_UPDATE);

            return $this->generateSuccessRedirect($addressUpdate);
        } catch (FormValidationException $e) {
            $message = $this->getTranslator()->trans('Please check your input: %s', ['%s' => $e->getMessage()], Front::MESSAGE_DOMAIN);
        }
        $this->getParserContext()->set('address_id', $addressId);

        $this->logger->error(\sprintf('Error during address creation process : %s', $message));

        $addressUpdate->setErrorMessage($message);

        $this->getParserContext()
          ->addForm($addressUpdate)
          ->setGeneralError($message)
        ;

        if ($addressUpdate->hasErrorUrl()) {
            return $this->generateErrorRedirect($addressUpdate);
        }
    }

    #[Route('/address/new', name: 'address_new', methods: ['GET'])]
    public function addressNew(): Response
    {
        $this->checkAuth();

        return $this->render('address');
    }

    #[Route('/address/new', name: 'address_create', methods: ['POST'])]
    public function addressCreate(AddressService $addressService): RedirectResponse
    {
        $this->checkAuth();

        $addressCreate = $this->createForm(AddressEditForm::FORM_NAME);

        try {
            $form = $this->validateForm($addressCreate, 'post');
            $addressService->createAddress($form);

            return $this->generateSuccessRedirect($addressCreate);
        } catch (FormValidationException $e) {
            $message = $this->getTranslator()->trans('Please check your input: %s', ['%s' => $e->getMessage()], Front::MESSAGE_DOMAIN);
        }

        $this->logger->error(\sprintf('Error during address creation process : %s', $message));

        $addressCreate->setErrorMessage($message);

        $this->getParserContext()
          ->addForm($addressCreate)
          ->setGeneralError($message)
        ;

        return $this->generateErrorRedirect($addressCreate);
    }

    /**
     * @throws \JsonException
     */
    #[Route('/address/delete/{addressId}', name: 'address_delete', requirements: ['addressId' => '\d+'], methods: ['POST'])]
    public function addressDelete(
        EventDispatcherInterface $eventDispatcher,
        CsrfTokenManagerInterface $csrfTokenManager,
        ?int $addressId = null,
    ): Response {
        $this->checkAuth();
        $error_message = false;

        $address = $this->hasValidCsrfToken($csrfTokenManager, self::DELETE_ADDRESS_TOKEN_ID)
            ? $this->findCustomerAddress($addressId)
            : null;

        if (!$address) {
            // If Ajax Request
            if ($this->getRequest()->isXmlHttpRequest()) {
                return $this->jsonResponse(
                    json_encode([
                        'success' => false,
                        'message' => $this->getTranslator()->trans(
                            'Error during address deletion process',
                            [],
                            Front::MESSAGE_DOMAIN
                        ),
                    ], \JSON_THROW_ON_ERROR)
                );
            }
            $url = $this->generateUrl('account_addresses', [
                'error' => true,
            ]);

            return new RedirectResponse($url);
        }

        $eventDispatcher->dispatch(new AddressEvent($address), TheliaEvents::ADDRESS_DELETE);


        $this->logger->error(\sprintf('Error during address deletion : %s', $error_message));

        // If Ajax Request
        if ($this->getRequest()->isXmlHttpRequest()) {
            if ($error_message) {
                return $this->jsonResponse(json_encode([
                    'success' => false,
                    'message' => $error_message,
                ], \JSON_THROW_ON_ERROR));
            }

            return $this->jsonResponse(
                json_encode([
                    'success' => true,
                    'message' => '',
                ], \JSON_THROW_ON_ERROR)
            );
        }
        $url = $this->generateUrl('account_addresses', [
            'delete_success' => true,
        ]);

        return new RedirectResponse($url);
    }

    #[Route('/address/default/{addressId}', name: 'address_default', requirements: ['addressId' => '\d+'], methods: ['POST'])]
    public function addressDefault(
        EventDispatcherInterface $eventDispatcher,
        CsrfTokenManagerInterface $csrfTokenManager,
        ?int $addressId = null,
    ): RedirectResponse {
        $this->checkAuth();

        $address = $this->hasValidCsrfToken($csrfTokenManager, self::DEFAULT_ADDRESS_TOKEN_ID)
            ? $this->findCustomerAddress($addressId)
            : null;

        if (null === $address) {
            return new RedirectResponse($this->generateUrl('account_addresses', [
                'error' => true,
            ]));
        }

        $event = new AddressEvent($address);
        $eventDispatcher->dispatch($event, TheliaEvents::ADDRESS_DEFAULT);

        return new RedirectResponse($this->generateUrl('account_addresses', [
            'default_success' => true,
        ]));

        return new RedirectResponse($this->generateUrl('account_addresses', [
            'error' => true,
        ]));
    }

    #[Route('/password', name: 'password', methods: 'POST')]
    public function password(EventDispatcherInterface $eventDispatcher): ?Response
    {
        $this->checkAuth();
        $customerPasswordUpdateForm = $this->createForm(FrontForm::CUSTOMER_PASSWORD_UPDATE);

        try {
            /** @var Customer $customer */
            $customer = $this->securityContext->getCustomerUser();

            $form = $this->validateForm($customerPasswordUpdateForm, 'post');

            $customerChangeEvent = $this->createEventInstance($form->getData());
            $customerChangeEvent->setCustomer($customer);
            $eventDispatcher->dispatch($customerChangeEvent, TheliaEvents::CUSTOMER_UPDATEPROFILE);

            return $this->generateSuccessRedirect($customerPasswordUpdateForm);
        } catch (FormValidationException $e) {
            $message = $this->translator->trans(
                'Please check your input: %s',
                [
                    '%s' => $e->getMessage(),
                ],
            );
        }

        $this->logger->error(
            \sprintf(
                'Error during customer password modification process : %s.',
                $message
            )
        );

        $customerPasswordUpdateForm->setErrorMessage($message);

        $this->parserContext
          ->addForm($customerPasswordUpdateForm)
          ->setGeneralError($message)
        ;

        // Redirect to error URL if defined
        if ($customerPasswordUpdateForm->hasErrorUrl()) {
            return $this->generateErrorRedirect($customerPasswordUpdateForm);
        }

        return $this->generateRedirectFromRoute('account_index');
    }

    /**
     * Hands the customer everything the shop knows about them, as the JSON
     * archive built by the core exporter and by every module declaring a
     * CustomerPersonalDataProviderInterface.
     *
     * The archive is never written anywhere: it is built and streamed inside
     * the request that asked for it. A file dropped in web/ or in a cache
     * directory would keep the personal data of one person behind a URL that
     * outlives their session, guessable by whoever knows the naming scheme.
     * For the same reason the route only answers POST, from an authenticated
     * session, with a CSRF token: a GET would be linkable, prefetchable and
     * loggable in every proxy on the way.
     */
    #[Route('/personal-data', name: 'personal_data', methods: ['POST'])]
    public function personalData(
        EventDispatcherInterface $eventDispatcher,
        CsrfTokenManagerInterface $csrfTokenManager,
    ): Response {
        $this->checkAuth();

        $customer = $this->getSecurityContext()->getCustomerUser();

        if (!$customer instanceof Customer || !$this->hasValidCsrfToken($csrfTokenManager, self::PERSONAL_DATA_TOKEN_ID)) {
            return new RedirectResponse($this->generateUrl('account_index', ['error' => true]));
        }

        $event = new CustomerPersonalDataExportEvent($customer);
        $eventDispatcher->dispatch($event, TheliaEvents::CUSTOMER_PERSONAL_DATA_EXPORT);

        $json = json_encode(
            $event->getPersonalData(),
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );

        $response = new Response($json, Response::HTTP_OK, ['Content-Type' => 'application/json']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            \sprintf('my-personal-data-%s.json', date('Y-m-d')),
        ));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store', true);
        $response->headers->addCacheControlDirective('must-revalidate', true);

        return $response;
    }

    /**
     * Addresses are only ever reachable through their owner: never look one up by
     * primary key alone, or any logged-in customer can read and act on someone
     * else's address book by walking the sequential ids.
     */
    private function findCustomerAddress(?int $addressId): ?Address
    {
        $customerId = $this->getSecurityContext()->getCustomerUser()?->getId();

        if (null === $addressId || null === $customerId) {
            return null;
        }

        return AddressQuery::create()
          ->filterByCustomerId($customerId)
          ->findPk($addressId);
    }

    private function hasValidCsrfToken(CsrfTokenManagerInterface $csrfTokenManager, string $tokenId): bool
    {
        $submittedToken = $this->getRequest()->request->get('_token');

        if (!\is_string($submittedToken)) {
            return false;
        }

        return $csrfTokenManager->isTokenValid(new CsrfToken($tokenId, $submittedToken));
    }

    private function createEventInstance($data): CustomerCreateOrUpdateEvent
    {
        return new CustomerCreateOrUpdateEvent(
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
            $data['lang_id'] ?? $this->getSession()->getLang()?->getId(),
            $data['reseller'] ?? null,
            $data['sponsor'] ?? null,
            $data['discount'] ?? null,
            $data['company'] ?? null,
            null,
            $data['state'] ?? null
        );
    }

    protected function createAddressEvent(Form $form): AddressCreateOrUpdateEvent
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
