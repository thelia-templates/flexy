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

use FlexyBundle\Service\Subscription\AccountSubscriptions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Core\HttpKernel\Exception\RedirectException;
use Thelia\Domain\Customer\Service\AuthenticationReturnUrl;
use Thelia\Model\Customer;
use Thelia\Model\Subscription;

/**
 * "My subscriptions" in the customer account.
 *
 * Without the core's subscriptions (the service is then absent: SubscriptionServicesPass) every route answers 404.
 * Every page and every answer is `no-store`: the shared cache would otherwise take a page that holds no cookie of its
 * own for a public one. A subscription of another customer reads as one that does not exist (404). The writes are
 * POSTs behind a CSRF token, answered by a redirect, like the payment methods; what the state refuses comes back as
 * a message on the page, never as a 500.
 */
#[Route('/account/subscriptions', name: 'account_subscription')]
class AccountSubscriptionController extends FlexyController
{
    public const ACTION_TOKEN_ID = 'subscription_action';

    #[Route('', name: 's', methods: ['GET'])]
    public function index(?AccountSubscriptions $subscriptions = null): Response
    {
        $customer = $this->signedInCustomer($subscriptions);

        return $this->noStore($this->render('account-subscriptions', ['subscriptions' => $subscriptions->listFor($customer)]));
    }

    #[Route('/{id}', name: '_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, ?AccountSubscriptions $subscriptions = null): Response
    {
        $customer = $this->signedInCustomer($subscriptions);
        $subscription = $this->owned($subscriptions, $customer, $id);

        return $this->noStore($this->render('account-subscription', [
            'subscription' => $subscriptions->view($subscription),
            'installments' => $subscriptions->installmentsOf($subscription),
            'methods' => $subscriptions->methodsFor($customer, $subscription),
        ]));
    }

    #[Route('/{id}/cancel', name: '_cancel', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function confirmCancellation(int $id, ?AccountSubscriptions $subscriptions = null): Response
    {
        $customer = $this->signedInCustomer($subscriptions);
        $subscription = $this->owned($subscriptions, $customer, $id);
        $view = $subscriptions->view($subscription);

        if (!$view['canCancel']) {
            return $this->back($id, 'refused');
        }

        return $this->noStore($this->render('account-subscription-cancel', [
            'subscription' => $view,
            'cancelOn' => $subscriptions->cancellationDate($subscription),
        ]));
    }

    #[Route('/{id}/cancel', name: '_cancel_do', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(Request $request, CsrfTokenManagerInterface $csrfTokenManager, int $id, ?AccountSubscriptions $subscriptions = null): Response
    {
        return $this->act($request, $csrfTokenManager, $id, $subscriptions, 'cancelled', static fn (AccountSubscriptions $service, Customer $customer, Subscription $subscription): ?string => $service->cancel($customer, $subscription));
    }

    #[Route('/{id}/pause', name: '_pause', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function pause(Request $request, CsrfTokenManagerInterface $csrfTokenManager, int $id, ?AccountSubscriptions $subscriptions = null): Response
    {
        return $this->act($request, $csrfTokenManager, $id, $subscriptions, 'paused', static fn (AccountSubscriptions $service, Customer $customer, Subscription $subscription): ?string => $service->pause($customer, $subscription));
    }

    #[Route('/{id}/resume', name: '_resume', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function resume(Request $request, CsrfTokenManagerInterface $csrfTokenManager, int $id, ?AccountSubscriptions $subscriptions = null): Response
    {
        return $this->act($request, $csrfTokenManager, $id, $subscriptions, 'resumed', static fn (AccountSubscriptions $service, Customer $customer, Subscription $subscription): ?string => $service->resume($customer, $subscription));
    }

    #[Route('/{id}/payment-method', name: '_payment_method', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function changePaymentMethod(Request $request, CsrfTokenManagerInterface $csrfTokenManager, int $id, ?AccountSubscriptions $subscriptions = null): Response
    {
        $methodId = (int) $request->request->get('payment_method');

        return $this->act($request, $csrfTokenManager, $id, $subscriptions, 'method_changed', static fn (AccountSubscriptions $service, Customer $customer, Subscription $subscription): ?string => $service->changePaymentMethod($customer, $subscription, $methodId));
    }

    /**
     * @param callable(AccountSubscriptions, Customer, Subscription): ?string $action
     */
    private function act(Request $request, CsrfTokenManagerInterface $csrfTokenManager, int $id, ?AccountSubscriptions $subscriptions, string $done, callable $action): Response
    {
        $customer = $this->signedInCustomer($subscriptions);

        if (!$csrfTokenManager->isTokenValid(new CsrfToken(self::ACTION_TOKEN_ID, (string) $request->request->get('_token')))) {
            throw new AccessDeniedHttpException();
        }

        $subscription = $this->owned($subscriptions, $customer, $id);

        try {
            $refusal = $action($subscriptions, $customer, $subscription);
        } catch (\Throwable $throwable) {
            // The class only: what a payment module says may quote a token.
            $this->logger->error(\sprintf('Error during a subscription action: %s.', $throwable::class));
            $refusal = 'error';
        }

        return $this->back($id, $refusal ?? $done);
    }

    private function back(int $id, string $notice): Response
    {
        return $this->noStore($this->generateRedirect($this->generateUrl('account_subscription_show', ['id' => $id, 'notice' => $notice])));
    }

    /**
     * @phpstan-assert AccountSubscriptions $subscriptions
     */
    private function signedInCustomer(?AccountSubscriptions $subscriptions): Customer
    {
        if (null === $subscriptions) {
            throw new NotFoundHttpException();
        }

        $this->checkAuth();
        $customer = $this->getSecurityContext()->getCustomerUser();

        // A guest of the checkout sits in the session like a customer, and has no account to manage anything from.
        if (!$customer instanceof Customer || $customer->isGuest()) {
            throw new RedirectException($this->generateUrl('customer_login', [
                AuthenticationReturnUrl::PARAMETER => $this->getRequest()->getRequestUri(),
            ]));
        }

        return $customer;
    }

    private function owned(AccountSubscriptions $subscriptions, Customer $customer, int $id): Subscription
    {
        return $subscriptions->findOwned($customer, $id) ?? throw new NotFoundHttpException();
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->remove('Expires');

        return $response;
    }
}
