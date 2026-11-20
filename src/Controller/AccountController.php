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
use FlexyBundle\Service\CompanyAccount;
use Propel\Runtime\Propel;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Core\Event\Address\AddressCreateOrUpdateEvent;
use Thelia\Core\Event\Customer\CustomerPersonalDataExportEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Addressing\Exception\AddressNotFoundException;
use Thelia\Domain\Addressing\Service\AddressAccess;
use Thelia\Domain\Addressing\Service\AddressService;
use Thelia\Domain\Company\CompanyFacade;
use Thelia\Domain\Company\Exception\CompanyException;
use Thelia\Domain\Customer\DTO\CustomerRegisterDTO;
use Thelia\Domain\Customer\Exception\CustomerException;
use Thelia\Domain\Customer\Service\CustomerUpdateService;
use Thelia\Form\Definition\FrontForm;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\Address;
use Thelia\Model\AddressQuery;
use Thelia\Model\Customer;
use Thelia\Model\Event\AddressEvent;
use Thelia\Model\Map\AddressTableMap;

#[Route('/account', name: 'account_')]
class AccountController extends FlexyController
{
    public const ADDRESS_ACTION_TOKEN_ID = 'address_action';

    public const PERSONAL_DATA_TOKEN_ID = 'personal_data';

    private ?CompanyAccount $companyAccount = null;

    // A setter: the constructor is the one of the parent, shared by every controller of the theme.
    #[Required]
    public function setCompanyAccount(CompanyAccount $companyAccount): void
    {
        $this->companyAccount = $companyAccount;
    }

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $this->checkAuth();

        return $this->render('account');
    }

    #[Route('/addresses', name: 'addresses')]
    public function addresses(): Response
    {
        $this->checkAuth();

        return $this->render('account-addresses', ['sharedAddressIds' => $this->sharedAddressIds()]);
    }

    #[Route('/address/new', name: 'address_new', methods: ['GET'])]
    public function addressNew(): Response
    {
        $this->checkAuth();

        return $this->render('address');
    }

    #[Route('/address/new', name: 'address_create', methods: ['POST'])]
    public function addressCreate(AddressService $addressService, EventDispatcherInterface $eventDispatcher, CompanyFacade $companyFacade): Response
    {
        $this->checkAuth();

        return $this->saveAddress($addressService, null, $eventDispatcher, $companyFacade);
    }

    #[Route('/address/{addressId}', name: 'address', requirements: ['addressId' => '\d+'], methods: ['GET'])]
    public function address(DataAccessService $dataAccessService, AddressAccess $addressAccess, int $addressId): Response
    {
        $this->checkEditableAddress($addressAccess, $addressId);

        // Same reason as the order page: an empty API result must not render in 200.
        if (null === $dataAccessService->resources('/api/front/account/addresses/'.$addressId)) {
            throw new NotFoundHttpException();
        }

        return $this->render('address-update', ['addressId' => $addressId]);
    }

    #[Route('/address/{addressId}', name: 'address_update', requirements: ['addressId' => '\d+'], methods: ['POST'])]
    public function addressUpdate(AddressService $addressService, EventDispatcherInterface $eventDispatcher, CompanyFacade $companyFacade, int $addressId): Response
    {
        $this->checkAuth();

        return $this->saveAddress($addressService, $addressId, $eventDispatcher, $companyFacade);
    }

    #[Route('/address/delete/{addressId}', name: 'address_delete', requirements: ['addressId' => '\d+'], methods: ['POST'])]
    public function addressDelete(
        AddressService $addressService,
        CsrfTokenManagerInterface $csrfTokenManager,
        Request $request,
        int $addressId,
    ): RedirectResponse {
        $this->checkAuth();
        $this->checkAddressActionToken($csrfTokenManager, $request);

        try {
            $addressService->deleteAddress($addressId);
        } catch (AddressNotFoundException|CustomerException $e) {
            $this->logger->error(\sprintf('Error during address deletion process: %s.', $e->getMessage()));

            return $this->generateRedirect($this->generateUrl('account_addresses', ['error' => true]));
        }

        return $this->generateRedirect($this->generateUrl('account_addresses', ['delete_success' => true]));
    }

    #[Route('/address/default/{addressId}', name: 'address_default', requirements: ['addressId' => '\d+'], methods: ['POST'])]
    public function addressDefault(
        EventDispatcherInterface $eventDispatcher,
        AddressAccess $addressAccess,
        CsrfTokenManagerInterface $csrfTokenManager,
        Request $request,
        int $addressId,
    ): RedirectResponse {
        $this->checkAuth();
        $this->checkAddressActionToken($csrfTokenManager, $request);

        $customer = $this->getSecurityContext()->getCustomerUser();
        $address = $addressAccess->find($customer, $addressId);

        // The default address is the one of the account that owns it: an address a colleague
        // shared can be used, never made the default one of someone else.
        if (null === $address || (int) $address->getCustomerId() !== (int) $customer?->getId()) {
            return $this->generateRedirect($this->generateUrl('account_addresses', ['error' => true]));
        }

        $eventDispatcher->dispatch(new AddressEvent($address), TheliaEvents::ADDRESS_DEFAULT);

        return $this->generateRedirect($this->generateUrl('account_addresses', ['default_success' => true]));
    }

    #[Route('/password', name: 'password', methods: ['POST'])]
    public function password(CustomerUpdateService $customerUpdateService): Response
    {
        $this->checkAuth();

        $form = $this->createForm(FrontForm::CUSTOMER_PASSWORD_UPDATE);

        try {
            $validatedForm = $this->validateForm($form, Request::METHOD_POST);

            // updateProfile() guards every field with a null check, so a password-only
            // DTO leaves the rest of the profile untouched.
            $customerUpdateService->updateCustomer(
                new CustomerRegisterDTO(password: $validatedForm->get('password')->getData()),
                $this->getSecurityContext()->getCustomerUser(),
            );

            // A stolen session id must not survive the very action taken to lock an
            // attacker out. Attributes are carried over, so the customer stays signed in.
            $this->getSession()->migrate(true);

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $e) {
            $message = $this->translator->trans('Please check your input: %s', ['%s' => $e->getMessage()]);
        }

        $this->logger->error(\sprintf('Error during customer password modification process: %s.', $message));

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

    /**
     * Streams everything the shop holds about the logged-in customer, as the JSON
     * archive built by the core exporter and by every module declaring a
     * CustomerPersonalDataProviderInterface.
     *
     * The archive is never written anywhere: it is built and streamed inside the
     * request that asked for it. A file dropped in public/ or in a cache directory
     * would keep the personal data of one person behind a URL that outlives their
     * session, guessable by whoever knows the naming scheme. For the same reason
     * the route only answers POST, from an authenticated session, with a CSRF
     * token: a GET would be linkable, prefetchable and loggable in every proxy on
     * the way.
     */
    #[Route('/personal-data', name: 'personal_data', methods: ['POST'])]
    public function personalData(
        EventDispatcherInterface $eventDispatcher,
        CsrfTokenManagerInterface $csrfTokenManager,
        Request $request,
    ): Response {
        $this->checkAuth();

        $customer = $this->getSecurityContext()->getCustomerUser();
        $token = new CsrfToken(self::PERSONAL_DATA_TOKEN_ID, (string) $request->request->get('_token'));

        if (!$customer instanceof Customer || !$csrfTokenManager->isTokenValid($token)) {
            return $this->generateRedirect($this->generateUrl('account_index', ['error' => true]));
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
     * Both address actions change state, so they are POST-only and carry a CSRF token:
     * the session cookie is SameSite=Lax, which a top-level navigation would still send.
     */
    private function checkAddressActionToken(CsrfTokenManagerInterface $csrfTokenManager, Request $request): void
    {
        $token = new CsrfToken(self::ADDRESS_ACTION_TOKEN_ID, (string) $request->request->get('_token'));

        if (!$csrfTokenManager->isTokenValid($token)) {
            throw new AccessDeniedHttpException();
        }
    }

    /**
     * The page that changes an address is the one of an address the customer may change: their
     * own, or one shared with their company that their role manages. The core leaves
     * `Get /front/account/addresses/{id}` open to whoever may use the address, so what makes
     * the page refuse is decided here, by the permission checker. Answers like a missing
     * address, so that an id cannot be probed for existence.
     */
    private function checkEditableAddress(AddressAccess $addressAccess, int $addressId): void
    {
        $this->checkAuth();

        $customer = $this->getSecurityContext()->getCustomerUser();
        $address = $addressAccess->find($customer, $addressId);

        if (null === $address || !$customer instanceof Customer || !$addressAccess->canEdit($customer, $address)) {
            throw new NotFoundHttpException();
        }
    }

    private function saveAddress(AddressService $addressService, ?int $addressId, EventDispatcherInterface $eventDispatcher, CompanyFacade $companyFacade): Response
    {
        $form = $this->createForm(AddressEditForm::FORM_NAME);

        // A new address is known to the controller only through the event that creates it.
        $created = null;
        $listener = static function (AddressCreateOrUpdateEvent $event) use (&$created): void {
            $created = $event->getAddress();
        };
        $eventDispatcher->addListener(TheliaEvents::ADDRESS_CREATE, $listener, -1000);

        try {
            $validatedForm = $this->validateForm($form, Request::METHOD_POST);

            // The address and its sharing are one change: a sharing the company refuses saves nothing.
            $this->inOneTransaction(function () use ($addressService, $addressId, $validatedForm, $companyFacade, &$created): void {
                $addressService->updateOrCreateAddress($addressId, $validatedForm);
                $this->shareAddress($companyFacade, $addressId, $created, $validatedForm->has('share_with_company') && true === $validatedForm->get('share_with_company')->getData());
            });

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $e) {
            $message = $this->translator->trans('Please check your input: %s', ['%s' => $e->getMessage()]);
        } catch (AddressNotFoundException|CustomerException|CompanyException $e) {
            $message = $this->translator->trans('An error has occurred, please try again later');
        }

        $eventDispatcher->removeListener(TheliaEvents::ADDRESS_CREATE, $listener);
        $this->logger->error(\sprintf('Error during address save process: %s.', $message));

        $form->setErrorMessage($message);
        $this->parserContext
            ->addForm($form)
            ->setGeneralError($message)
        ;

        if ($form->hasErrorUrl()) {
            return $this->generateErrorRedirect($form);
        }

        return $this->generateRedirectFromRoute('account_addresses');
    }

    /**
     * @param callable(): void $work
     */
    private function inOneTransaction(callable $work): void
    {
        $connection = Propel::getWriteConnection(AddressTableMap::DATABASE_NAME);
        $connection->beginTransaction();

        try {
            $work();
            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }
    }

    /**
     * The addresses of the address book that belong to the company of the customer, to draw them
     * in a group of their own. Empty, and nothing asked of the database, for a customer who
     * belongs to no company: their address book is the list it always was.
     *
     * @return list<int>
     */
    private function sharedAddressIds(): array
    {
        $membership = $this->companyAccount?->membership();

        if (null === $membership) {
            return [];
        }

        return array_map('intval', AddressQuery::create()->filterByCompanyId($membership->companyId)->select('Id')->find()->getData());
    }

    /**
     * The "share with my company" box: the address is shared with the company of the buyer when
     * it is ticked and not otherwise. A customer who belongs to no company has no such box, and
     * what an address was before is left alone for them. The rule is the core's: any buyer shares
     * what they created, and the sharing is taken back by its creator or a buyer who manages the
     * shared addresses.
     */
    private function shareAddress(CompanyFacade $companyFacade, ?int $addressId, ?Address $created, bool $shared): void
    {
        $customer = $this->getSecurityContext()->getCustomerUser();
        $address = $created ?? (null === $addressId ? null : AddressQuery::create()->findPk($addressId));

        if (!$customer instanceof Customer || null === $address || true !== $this->companyAccount?->isMember()) {
            return;
        }

        if ($shared === (null !== $address->getCompanyId())) {
            return;
        }

        $companyFacade->shareAddress($customer, $address, $shared);
    }
}
