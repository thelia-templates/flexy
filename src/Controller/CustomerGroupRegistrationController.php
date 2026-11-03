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

use FlexyBundle\Service\GuestCheckoutGate;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Domain\Customer\Service\AuthenticationReturnUrl;
use Thelia\Domain\Customer\Service\CustomerAuthenticator;
use Thelia\Domain\Customer\Service\CustomerGroupRegistration;
use Thelia\Domain\Customer\Service\CustomerRegistrationService;
use Thelia\Domain\Localization\Service\LangService;

/**
 * The sign-up form a customer group hands out through a secret link: the standard
 * registration form, whose new account joins the group the link opens.
 *
 * The token is the one of the URL, read again at every request and checked against the
 * database before anything is shown or written: the form carries no group and no token of
 * its own, so nothing the visitor posts can name a group. A token that opens nothing (never
 * issued, regenerated, closed, malformed) answers like any page that does not exist.
 */
#[Route('/register/{token}', requirements: ['token' => '[^/]+'])]
class CustomerGroupRegistrationController extends FlexyController
{
    #[Route('', name: 'customer_group_register', methods: ['GET'])]
    public function register(
        string $token,
        CustomerGroupRegistration $customerGroupRegistration,
        AuthenticationReturnUrl $authenticationReturnUrl,
        SessionInterface $session,
    ): Response {
        $this->requireAnOpenLink($token, $customerGroupRegistration);

        return $this->renderRegistrationPage($authenticationReturnUrl, $session, ['registrationToken' => $token]);
    }

    #[Route('', name: 'customer_group_register_create', methods: ['POST'])]
    public function registerCreate(
        string $token,
        CustomerGroupRegistration $customerGroupRegistration,
        CustomerRegistrationService $customerRegistrationProcessor,
        SessionInterface $session,
        LangService $langService,
        CustomerAuthenticator $customerLoginProcessor,
        GuestCheckoutGate $guestCheckoutGate,
        AuthenticationReturnUrl $authenticationReturnUrl,
    ): RedirectResponse {
        if ($this->getSecurityContext()->hasAuthenticatedCustomerUser()) {
            return $this->generateRedirect('/account');
        }

        // Before the form is even read: a link that no longer opens a group creates nothing.
        $this->requireAnOpenLink($token, $customerGroupRegistration);

        return $this->registerCustomerFromForm(
            $customerRegistrationProcessor,
            $session,
            $langService,
            $customerLoginProcessor,
            $guestCheckoutGate,
            $authenticationReturnUrl,
            $token,
            $this->generateUrl('customer_group_register', ['token' => $token]),
        );
    }

    private function requireAnOpenLink(string $token, CustomerGroupRegistration $customerGroupRegistration): void
    {
        if (null === $customerGroupRegistration->groupForToken($token)) {
            throw new NotFoundHttpException();
        }
    }
}
