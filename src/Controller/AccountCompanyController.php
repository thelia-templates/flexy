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

use FlexyBundle\Service\CollectionPaginator;
use FlexyBundle\Service\CompanyAccount;
use FlexyBundle\Service\CompanyRoleChoices;
use FlexyBundle\Service\GuestCheckoutGate;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Api\Service\Company\CompanyExceptionTranslator;
use Thelia\Api\Service\Company\CompanyInvitationMailer;
use Thelia\Domain\Company\CompanyPermission;
use Thelia\Domain\Company\DTO\CompanyInvitation;
use Thelia\Domain\Company\Exception\InvalidCompanyIdentifiersException;
use Thelia\Domain\Company\Service\CompanyAccountWriter;
use Thelia\Domain\Company\Service\CompanyInvitationService;
use Thelia\Domain\Customer\Service\AuthenticationReturnUrl;
use Thelia\Domain\Customer\Service\CustomerAuthenticator;
use Thelia\Domain\Customer\Service\CustomerRegistrationService;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\CompanyQuery;
use Thelia\Model\Currency;
use Thelia\Model\Customer;
use Thelia\Model\CustomerQuery;

/**
 * "My company" in the customer account: the details of the company, its buyers, the invitation
 * of a new one, and the page an invited person opens from the e-mail.
 *
 * What a page shows and what its route accepts are decided by the same answer, the one of the
 * core permission checker (CompanyAccount): a button the buyer does not hold is not drawn, and
 * the route behind it refuses. A customer who belongs to no company has no such pages, they
 * answer 404 as any page that does not exist. The changes go through
 * core, in the same service the account API calls (CompanyAccountWriter), so that the rules
 * (no role beyond the issuer's, no identifier change once an order was left without VAT, never
 * the last administrator) are the rules of the page, written once. Each write is a POST with a CSRF token, answered by a redirect.
 */
#[Route('/account/company', name: 'account_company')]
class AccountCompanyController extends FlexyController
{
    public const ACTION_TOKEN_ID = 'company_action';

    public const BUYERS_PER_PAGE = 10;

    private const REGISTER_FORM_NAME = 'flexybundle_form_customer_register_form';

    #[Route('', name: '', methods: ['GET'])]
    public function index(CompanyAccount $companyAccount): Response
    {
        $this->requireMember($companyAccount);

        return $this->render('account-company');
    }

    #[Route('', name: '_update', methods: ['POST'])]
    public function update(
        CompanyAccount $companyAccount,
        CompanyAccountWriter $writer,
        CsrfTokenManagerInterface $csrfTokenManager,
        Request $request,
    ): Response {
        $this->requirePermission($companyAccount, CompanyPermission::COMPANY_EDIT);
        $this->checkToken($csrfTokenManager, $request);

        $caller = $companyAccount->customer() ?? throw new AccessDeniedHttpException();

        try {
            CompanyExceptionTranslator::translating(fn () => $writer->updateCompany(
                $caller,
                $this->textOf($request, 'name'),
                $this->optionalTextOf($request, 'siret'),
                $this->optionalTextOf($request, 'vat_number'),
                $this->optionalTextOf($request, 'notification_email'),
            ));
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getPrevious() instanceof InvalidCompanyIdentifiersException) {
                // The page comes back with what was typed and what is wrong with each identifier,
                // in the language of the buyer, instead of a redirect that would lose both.
                return $this->render('account-company', [
                    'identifierErrors' => $this->identifierErrors($exception->getPrevious()),
                    'typed' => [
                        'name' => $this->textOf($request, 'name'),
                        'siret' => $this->optionalTextOf($request, 'siret') ?? '',
                        'vat_number' => $this->optionalTextOf($request, 'vat_number') ?? '',
                        'notification_email' => $this->optionalTextOf($request, 'notification_email') ?? '',
                    ],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->back('account_company', $this->errorOf($exception, ['409' => 'identifiers_locked']));
        }

        return $this->back('account_company', 'company_updated', true);
    }

    #[Route('/buyers', name: '_buyers', methods: ['GET'])]
    public function buyers(CompanyAccount $companyAccount, CollectionPaginator $paginator, CompanyRoleChoices $roleChoices): Response
    {
        $this->requirePermission($companyAccount, CompanyPermission::BUYER_MANAGE);

        $page = $paginator->page(
            '/api/front/account/company/customers',
            [],
            CollectionPaginator::requestedPage($this->getRequest()),
            self::BUYERS_PER_PAGE,
        );

        return $this->render('account-company-buyers', [
            'buyers' => $page->members,
            'pagination' => $page->pagination(),
            'roles' => $roleChoices->grantable(),
            'roleTitles' => $roleChoices->titles(),
            'currentCustomerId' => $companyAccount->customer()?->getId(),
            'ownLimits' => $this->ownLimits($companyAccount),
            'currencySymbol' => (string) Currency::getDefaultCurrency()->getSymbol(),
        ]);
    }

    #[Route('/buyers/{buyerId}', name: '_buyer_update', requirements: ['buyerId' => '\d+'], methods: ['POST'])]
    public function updateBuyer(
        CompanyAccount $companyAccount,
        CompanyAccountWriter $writer,
        CsrfTokenManagerInterface $csrfTokenManager,
        Request $request,
        int $buyerId,
    ): RedirectResponse {
        $this->requirePermission($companyAccount, CompanyPermission::BUYER_MANAGE);
        $this->checkToken($csrfTokenManager, $request);

        $caller = $companyAccount->customer() ?? throw new AccessDeniedHttpException();

        try {
            CompanyExceptionTranslator::translating(fn () => $writer->changeBuyer(
                $caller,
                $buyerId,
                $this->optionalTextOf($request, 'role'),
                $this->optionalTextOf($request, 'order_limit'),
                $this->optionalTextOf($request, 'period_limit'),
                $this->optionalTextOf($request, 'period'),
            ));
        } catch (HttpExceptionInterface $exception) {
            return $this->back('account_company_buyers', $this->errorOf($exception, ['409' => 'last_administrator', '403' => 'beyond_your_rights']));
        }

        return $this->back('account_company_buyers', 'buyer_updated', true);
    }

    #[Route('/buyers/{buyerId}/detach', name: '_buyer_detach', requirements: ['buyerId' => '\d+'], methods: ['POST'])]
    public function detachBuyer(
        CompanyAccount $companyAccount,
        CompanyAccountWriter $writer,
        CsrfTokenManagerInterface $csrfTokenManager,
        Request $request,
        int $buyerId,
    ): RedirectResponse {
        $this->requirePermission($companyAccount, CompanyPermission::BUYER_MANAGE);
        $this->checkToken($csrfTokenManager, $request);

        $caller = $companyAccount->customer() ?? throw new AccessDeniedHttpException();

        try {
            CompanyExceptionTranslator::translating(static fn () => $writer->detachBuyer($caller, $buyerId));
        } catch (HttpExceptionInterface $exception) {
            return $this->back('account_company_buyers', $this->errorOf($exception, ['409' => 'last_administrator', '403' => 'beyond_your_rights']));
        }

        return $this->back('account_company_buyers', 'buyer_detached', true);
    }

    #[Route('/invitations', name: '_invite', methods: ['POST'])]
    public function invite(
        CompanyAccount $companyAccount,
        CompanyInvitationService $invitations,
        CompanyInvitationMailer $mailer,
        CsrfTokenManagerInterface $csrfTokenManager,
        Request $request,
    ): RedirectResponse {
        $this->requirePermission($companyAccount, CompanyPermission::BUYER_MANAGE);
        $this->checkToken($csrfTokenManager, $request);

        $issuer = $companyAccount->customer();
        $company = $companyAccount->company();
        $email = mb_strtolower($this->textOf($request, 'email'));
        $roleCode = $this->textOf($request, 'role');

        if (null === $issuer || null === $company || false === filter_var($email, \FILTER_VALIDATE_EMAIL) || '' === $roleCode) {
            return $this->back('account_company_buyers', 'invalid_invitation');
        }

        $limits = $this->invitedLimits($request, $companyAccount);

        try {
            $token = CompanyExceptionTranslator::translating(static fn (): string => $invitations->createToken(
                $company,
                $email,
                $roleCode,
                $issuer,
                ...$limits,
            ));
        } catch (HttpExceptionInterface $exception) {
            return $this->back('account_company_buyers', $this->errorOf($exception, ['429' => 'too_many_invitations', '409' => 'already_a_buyer', '403' => 'beyond_your_rights']));
        }

        try {
            $mailer->send($email, $token, (string) $company->getName(), trim($issuer->getFirstname().' '.$issuer->getLastname()));
        } catch (\Throwable $throwable) {
            // The class only: what a mailer says may quote the address.
            $this->logger->error(\sprintf('Error during company invitation sending: %s.', $throwable::class));

            return $this->back('account_company_buyers', 'invitation_not_sent');
        }

        return $this->back('account_company_buyers', 'invitation_sent', true);
    }

    /**
     * The page the link of the e-mail opens. Every token that stands for nothing (altered, expired,
     * already used) answers the same page, with the same status: nothing says which one it was.
     */
    #[Route('/invitation/{token}', name: '_invitation', requirements: ['token' => '[^/]+'], methods: ['GET'])]
    public function invitation(string $token, CompanyInvitationService $invitations, AuthenticationReturnUrl $authenticationReturnUrl): Response
    {
        $invitation = $invitations->read($token);

        if (null === $invitation) {
            return $this->invalidInvitation();
        }

        $authenticationReturnUrl->capture();

        return $this->renderInvitation($token, $invitation);
    }

    #[Route('/invitation/{token}', name: '_invitation_accept', requirements: ['token' => '[^/]+'], methods: ['POST'])]
    public function acceptInvitation(
        string $token,
        CompanyInvitationService $invitations,
        CsrfTokenManagerInterface $csrfTokenManager,
        Request $request,
    ): Response {
        $invitation = $invitations->read($token);

        if (null === $invitation) {
            return $this->invalidInvitation();
        }

        $customer = $this->signedInCustomer();

        if (null === $customer) {
            throw new AccessDeniedHttpException();
        }

        $this->checkToken($csrfTokenManager, $request);

        try {
            CompanyExceptionTranslator::translating(static fn () => $invitations->accept($token, $customer));
        } catch (HttpExceptionInterface) {
            // Sent to another address, already used, a guest: the same page for all of them.
            return $this->invalidInvitation();
        }

        return $this->back('account_company', 'joined', true);
    }

    /**
     * The person invited has no account yet: it is created for the address the invitation was sent
     * to (never the one a request would name) with the password they chose, and joins the company
     * at once. The address of the invitation is the proof of the mailbox, as the link is the only
     * way to get here.
     */
    #[Route('/invitation/{token}/register', name: '_invitation_register', requirements: ['token' => '[^/]+'], methods: ['POST'])]
    public function registerFromInvitation(
        string $token,
        CompanyInvitationService $invitations,
        CustomerRegistrationService $customerRegistrationProcessor,
        SessionInterface $session,
        LangService $langService,
        CustomerAuthenticator $customerLoginProcessor,
        GuestCheckoutGate $guestCheckoutGate,
        AuthenticationReturnUrl $authenticationReturnUrl,
        Request $request,
    ): Response {
        $invitation = $invitations->read($token);

        if (null === $invitation || null !== $this->signedInCustomer() || $this->hasAnAccount($invitation->email)) {
            return null === $invitation ? $this->invalidInvitation() : $this->generateRedirect($this->generateUrl('account_company_invitation', ['token' => $token]));
        }

        // The address is the invitation's, whatever the request holds.
        $posted = $request->request->all();
        $posted[self::REGISTER_FORM_NAME] = [...(\is_array($posted[self::REGISTER_FORM_NAME] ?? null) ? $posted[self::REGISTER_FORM_NAME] : []), 'email' => $invitation->email];
        $request->request->replace($posted);

        $response = $this->registerCustomerFromForm(
            $customerRegistrationProcessor,
            $session,
            $langService,
            $customerLoginProcessor,
            $guestCheckoutGate,
            $authenticationReturnUrl,
            null,
            $this->generateUrl('account_company_invitation', ['token' => $token]),
        );

        $registered = $this->registeredCustomer($session, $invitation);

        if (null !== $registered) {
            try {
                CompanyExceptionTranslator::translating(static fn () => $invitations->accept($token, $registered));
            } catch (HttpExceptionInterface) {
                return $this->invalidInvitation();
            }
        }

        return $response;
    }

    private function renderInvitation(string $token, CompanyInvitation $invitation): Response
    {
        $customer = $this->signedInCustomer();

        $company = CompanyQuery::create()->findPk($invitation->companyId);

        if (null === $company) {
            return $this->invalidInvitation();
        }

        $state = match (true) {
            null !== $customer && 0 === strcasecmp((string) $customer->getEmail(), $invitation->email) => 'accept',
            null !== $customer => 'other_account',
            $this->hasAnAccount($invitation->email) => 'sign_in',
            default => 'register',
        };

        return $this->render('account-company-invitation', [
            'invalid' => false,
            'state' => $state,
            'token' => $token,
            'companyName' => (string) $company->getName(),
            'email' => $invitation->email,
        ]);
    }

    private function invalidInvitation(): Response
    {
        return $this->render('account-company-invitation', ['invalid' => true], Response::HTTP_NOT_FOUND);
    }

    private function hasAnAccount(string $email): bool
    {
        return CustomerQuery::create()->filterByEmail($email)->filterByIsGuest(0)->exists();
    }

    /**
     * The account the sign-up form just created, when it is the one of the invitation.
     */
    private function registeredCustomer(SessionInterface $session, CompanyInvitation $invitation): ?Customer
    {
        $customerId = $session->get('registration_customer_id');

        if (!is_numeric($customerId)) {
            return null;
        }

        $customer = CustomerQuery::create()->findPk((int) $customerId);

        return $customer instanceof Customer && 0 === strcasecmp((string) $customer->getEmail(), $invitation->email) ? $customer : null;
    }

    private function signedInCustomer(): ?Customer
    {
        $customer = $this->getSecurityContext()->getCustomerUser();

        return $customer instanceof Customer && !$customer->isGuest() ? $customer : null;
    }

    /**
     * What is wrong with each identifier, worded in the language of the buyer, by the name of the field.
     *
     * @return array<string, list<string>>
     */
    private function identifierErrors(InvalidCompanyIdentifiersException $refusal): array
    {
        $errors = [];

        foreach ($refusal->violations() as $violation) {
            $errors[$violation->field][] = $this->getTranslator()->trans($violation->message, $violation->parameters);
        }

        return $errors;
    }

    /**
     * The limits of the signed-in buyer, which an invitation they send starts from.
     *
     * @return array{orderLimit: ?string, periodLimit: ?string, period: ?string}
     */
    private function ownLimits(CompanyAccount $companyAccount): array
    {
        $membership = $companyAccount->membership();

        return [
            'orderLimit' => $this->plainAmount($membership?->orderLimit),
            'periodLimit' => $this->plainAmount($membership?->periodLimit),
            'period' => $membership?->period,
        ];
    }

    /**
     * The limits an invitation gives: what the form says, and for a limit it leaves blank the one
     * the buyer who invites holds, since the core refuses a limit higher than theirs and a blank
     * is higher than any. A buyer with no limit gives the limits they type, or none.
     *
     * @return array{?string, ?string, ?string} order limit, period limit, period
     */
    private function invitedLimits(Request $request, CompanyAccount $companyAccount): array
    {
        $own = $this->ownLimits($companyAccount);
        $periodLimit = $this->optionalTextOf($request, 'period_limit');
        $period = $this->optionalTextOf($request, 'period');

        if (null === $periodLimit) {
            $periodLimit = $own['periodLimit'];
            $period = null === $periodLimit ? null : $own['period'];
        } else {
            $period ??= $own['period'];
        }

        return [$this->optionalTextOf($request, 'order_limit') ?? $own['orderLimit'], $periodLimit, $period];
    }

    private function plainAmount(?string $amount): ?string
    {
        if (null === $amount) {
            return null;
        }

        return str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount;
    }

    private function requireMember(CompanyAccount $companyAccount): void
    {
        $this->checkAuth();

        if (!$companyAccount->isMember()) {
            throw new NotFoundHttpException();
        }
    }

    private function requirePermission(CompanyAccount $companyAccount, string $permission): void
    {
        $this->requireMember($companyAccount);

        if (!$companyAccount->can($permission)) {
            throw new AccessDeniedHttpException();
        }
    }

    private function checkToken(CsrfTokenManagerInterface $csrfTokenManager, Request $request): void
    {
        if (!$csrfTokenManager->isTokenValid(new CsrfToken(self::ACTION_TOKEN_ID, (string) $request->request->get('_token')))) {
            throw new AccessDeniedHttpException();
        }
    }

    private function textOf(Request $request, string $field): string
    {
        $value = $request->request->all()[$field] ?? '';

        return \is_string($value) ? trim($value) : '';
    }

    private function optionalTextOf(Request $request, string $field): ?string
    {
        $value = $this->textOf($request, $field);

        return '' === $value ? null : $value;
    }

    /**
     * What the page says of a refusal of the API: the codes the page knows by status, else the
     * generic ones. Nothing the API wrote is shown, only a sentence of the theme.
     *
     * @param array<string, string> $byStatus
     */
    private function errorOf(HttpExceptionInterface $exception, array $byStatus): string
    {
        return $byStatus[(string) $exception->getStatusCode()] ?? match ($exception->getStatusCode()) {
            Response::HTTP_UNPROCESSABLE_ENTITY => 'invalid',
            Response::HTTP_NOT_FOUND => 'not_found',
            default => 'error',
        };
    }

    private function back(string $route, string $notice, bool $success = false): RedirectResponse
    {
        return $this->generateRedirect($this->generateUrl($route, [$success ? 'success' : 'error' => $notice]));
    }
}
