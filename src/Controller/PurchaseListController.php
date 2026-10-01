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

use FlexyBundle\Service\PurchaseListChoices;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Catalog\Exception\InvalidReferenceQuantityException;
use Thelia\Domain\CustomerList\Exception\InvalidPurchaseListException;
use Thelia\Domain\CustomerList\Exception\PurchaseListAccessDeniedException;
use Thelia\Domain\CustomerList\Exception\PurchaseListNotFoundException;
use Thelia\Domain\CustomerList\Exception\PurchaseListSourceNotFoundException;
use Thelia\Domain\CustomerList\PurchaseListFacade;
use Thelia\Model\Customer;
use Thelia\Model\CustomerList;

/**
 * The purchase lists of the customer account. The lines of a list are edited in the
 * quick order table the detail page opens; the writes here are the ones on a whole list,
 * each a POST carrying a CSRF token and answered by a redirect, like the address book.
 * A list the customer may not see answers as a list that does not exist.
 *
 * The cart and a past order are saved the same way, into a new list or added to one the
 * customer may change: the posted `listId` is `PurchaseListChoices::NEW_LIST` for a new list.
 */
#[Route('/account/purchase-lists', name: 'account_purchase_list')]
class PurchaseListController extends FlexyController
{
    public const ACTION_TOKEN_ID = 'purchase_list_action';

    #[Route('', name: 's', methods: ['GET'])]
    public function index(): Response
    {
        $this->checkAuth();

        return $this->render('account-purchase-lists');
    }

    #[Route('', name: '_create', methods: ['POST'])]
    public function create(PurchaseListFacade $purchaseListFacade, CsrfTokenManagerInterface $csrfTokenManager, Request $request): RedirectResponse
    {
        $customer = $this->signedInCustomer();
        $this->checkToken($csrfTokenManager, $request);

        try {
            $list = $purchaseListFacade->create($customer, (string) $request->request->get('title'));
        } catch (InvalidPurchaseListException) {
            return $this->generateRedirect($this->generateUrl('account_purchase_lists', ['error' => 'create']));
        }

        return $this->generateRedirect($this->generateUrl('account_purchase_list', ['listId' => $list->getId(), 'created' => 1]));
    }

    #[Route('/{listId}', name: '', requirements: ['listId' => '\d+'], methods: ['GET'])]
    public function show(PurchaseListFacade $purchaseListFacade, int $listId): Response
    {
        $customer = $this->signedInCustomer();

        try {
            $list = $purchaseListFacade->getVisible($customer, $listId);
        } catch (PurchaseListNotFoundException $exception) {
            throw new NotFoundHttpException('No such purchase list.', $exception);
        }

        return $this->render('account-purchase-list', [
            'purchaseListId' => (int) $list->getId(),
            'purchaseListTitle' => (string) $list->getTitle(),
            'purchaseListWritable' => $purchaseListFacade->canWrite($customer, $list),
        ]);
    }

    #[Route('/{listId}/rename', name: '_rename', requirements: ['listId' => '\d+'], methods: ['POST'])]
    public function rename(PurchaseListFacade $purchaseListFacade, CsrfTokenManagerInterface $csrfTokenManager, Request $request, int $listId): RedirectResponse
    {
        $customer = $this->signedInCustomer();
        $this->checkToken($csrfTokenManager, $request);

        try {
            $purchaseListFacade->rename($customer, $listId, (string) $request->request->get('title'));
        } catch (InvalidPurchaseListException) {
            return $this->generateRedirect($this->generateUrl('account_purchase_list', ['listId' => $listId, 'error' => 'rename']));
        } catch (PurchaseListNotFoundException $exception) {
            throw self::unreachable($exception);
        } catch (PurchaseListAccessDeniedException $exception) {
            throw new AccessDeniedHttpException('This purchase list cannot be changed.', $exception);
        }

        return $this->generateRedirect($this->generateUrl('account_purchase_list', ['listId' => $listId, 'renamed' => 1]));
    }

    #[Route('/{listId}/duplicate', name: '_duplicate', requirements: ['listId' => '\d+'], methods: ['POST'])]
    public function duplicate(PurchaseListFacade $purchaseListFacade, CsrfTokenManagerInterface $csrfTokenManager, Request $request, int $listId): RedirectResponse
    {
        $customer = $this->signedInCustomer();
        $this->checkToken($csrfTokenManager, $request);

        try {
            $copy = $purchaseListFacade->duplicate($customer, $listId);
        } catch (InvalidPurchaseListException) {
            return $this->generateRedirect($this->generateUrl('account_purchase_lists', ['error' => 'create']));
        } catch (PurchaseListNotFoundException $exception) {
            throw self::unreachable($exception);
        }

        return $this->generateRedirect($this->generateUrl('account_purchase_list', ['listId' => $copy->getId(), 'duplicated' => 1]));
    }

    #[Route('/{listId}/delete', name: '_delete', requirements: ['listId' => '\d+'], methods: ['POST'])]
    public function delete(PurchaseListFacade $purchaseListFacade, CsrfTokenManagerInterface $csrfTokenManager, Request $request, int $listId): RedirectResponse
    {
        $customer = $this->signedInCustomer();
        $this->checkToken($csrfTokenManager, $request);

        try {
            $purchaseListFacade->delete($customer, $listId);
        } catch (PurchaseListNotFoundException $exception) {
            throw self::unreachable($exception);
        } catch (PurchaseListAccessDeniedException $exception) {
            throw new AccessDeniedHttpException('This purchase list cannot be changed.', $exception);
        }

        return $this->generateRedirect($this->generateUrl('account_purchase_lists', ['deleted' => 1]));
    }

    #[Route('/from-cart', name: '_from_cart', methods: ['POST'])]
    public function fromCart(PurchaseListFacade $purchaseListFacade, CartFacade $cartFacade, CsrfTokenManagerInterface $csrfTokenManager, Request $request): RedirectResponse
    {
        $customer = $this->signedInCustomer();
        $this->checkToken($csrfTokenManager, $request);
        $cart = $cartFacade->getCartFromSession();

        // The session always has a cart, unsaved until something goes in it.
        if (null === $cart?->getId() || 0 === $cart->countCartItems()) {
            return $this->generateRedirect($this->generateUrl('checkout_cart'));
        }

        return $this->saveLines(
            $request,
            static fn (string $title): CustomerList => $purchaseListFacade->createFromCart($customer, $cart, $title),
            static fn (int $listId): CustomerList => $purchaseListFacade->appendFromCart($customer, $listId, $cart),
            fn (string $error): string => $this->generateUrl('checkout_cart', ['purchase_list_error' => $error]),
        );
    }

    #[Route('/from-order/{orderId}', name: '_from_order', requirements: ['orderId' => '\d+'], methods: ['POST'])]
    public function fromOrder(PurchaseListFacade $purchaseListFacade, CsrfTokenManagerInterface $csrfTokenManager, Request $request, int $orderId): RedirectResponse
    {
        $customer = $this->signedInCustomer();
        $this->checkToken($csrfTokenManager, $request);

        return $this->saveLines(
            $request,
            static fn (string $title): CustomerList => $purchaseListFacade->createFromOrder($customer, $orderId, $title),
            static fn (int $listId): CustomerList => $purchaseListFacade->appendFromOrder($customer, $listId, $orderId),
            fn (string $error): string => $this->generateUrl('account_order', ['orderId' => $orderId, 'purchase_list_error' => $error]),
        );
    }

    /**
     * @param \Closure(string): CustomerList $create
     * @param \Closure(int): CustomerList    $append
     * @param \Closure(string): string       $failureUrl
     */
    private function saveLines(Request $request, \Closure $create, \Closure $append, \Closure $failureUrl): RedirectResponse
    {
        $listId = (string) $request->request->get('listId');

        try {
            $list = PurchaseListChoices::NEW_LIST === $listId ? $create((string) $request->request->get('title')) : $append((int) $listId);
        } catch (InvalidPurchaseListException) {
            return $this->generateRedirect($failureUrl('title'));
        } catch (InvalidReferenceQuantityException) {
            return $this->generateRedirect($failureUrl('lines'));
        } catch (PurchaseListNotFoundException|PurchaseListSourceNotFoundException $exception) {
            throw self::unreachable($exception);
        } catch (PurchaseListAccessDeniedException $exception) {
            throw new AccessDeniedHttpException('This purchase list cannot be changed.', $exception);
        }

        return $this->generateRedirect($this->generateUrl('account_purchase_list', ['listId' => $list->getId(), PurchaseListChoices::NEW_LIST === $listId ? 'created' : 'appended' => 1]));
    }

    private function signedInCustomer(): Customer
    {
        $this->checkAuth();
        $customer = $this->getSecurityContext()->getCustomerUser();

        if (!$customer instanceof Customer) {
            throw new AccessDeniedHttpException();
        }

        return $customer;
    }

    private function checkToken(CsrfTokenManagerInterface $csrfTokenManager, Request $request): void
    {
        if (!$csrfTokenManager->isTokenValid(new CsrfToken(self::ACTION_TOKEN_ID, (string) $request->request->get('_token')))) {
            throw new AccessDeniedHttpException();
        }
    }

    /**
     * A list of another account answers as a list that does not exist; one the customer
     * sees without being allowed to change it answers 403, as on the account API.
     */
    private static function unreachable(\Throwable $exception): NotFoundHttpException
    {
        return new NotFoundHttpException('No such purchase list.', $exception);
    }
}
