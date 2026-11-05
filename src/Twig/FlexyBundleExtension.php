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

namespace FlexyBundle\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Customer\Service\AuthenticationReturnUrl;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class FlexyBundleExtension extends AbstractExtension
{
    /**
     * Pages of the sign-in journey never become a return destination: coming back to the
     * form that was just filled in is a detour, not where the visitor was going.
     */
    private const AUTHENTICATION_ROUTES = [
        'customer_login',
        'customer_login_action',
        'customer_register',
        'customer_register_create',
        'customer_informations',
        'customer_informations_create',
        'customer_activation',
        'customer_send_code',
        'password_forgotten',
        'password_forgotten_send',
        'password_reset_link',
        'password_resend',
        'password_reset',
        'password_reset_action',
        'password_reset_confirm',
        'checkout_identify',
        'checkout_identify_guest',
    ];

    public function __construct(
        private readonly SecurityContext $securityContext,
        private readonly LangService $langService,
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('getCurrentCustomer', [$this, 'getCurrentCustomer']),
            new TwigFunction('current_locale', [$this, 'currentLocale']),
            new TwigFunction('hasCustomerAccount', [$this, 'hasCustomerAccount']),
            new TwigFunction('sign_in_path', [$this, 'signInPath']),
            new TwigFunction('newsletter_enabled', [$this, 'newsletterEnabled']),
        ];
    }

    /**
     * Whether the shop takes newsletter sign-ups: the pages that offer one hide it otherwise.
     */
    public function newsletterEnabled(): bool
    {
        return ConfigQuery::isNewsletterEnabled();
    }

    public function getCurrentCustomer(): ?Customer
    {
        return $this->securityContext->getCustomerUser();
    }

    /**
     * Whether the visitor signed into an account, as opposed to merely being in the
     * session.
     *
     * A guest checking out sits under the same session key as a signed-in customer, so
     * anything that offers the account area — the profile menu, an "my orders" link —
     * has to ask this rather than whether a customer is there at all: those pages are
     * closed to a guest, and offering them would only lead to the login page.
     */
    public function hasCustomerAccount(): bool
    {
        return $this->securityContext->hasAuthenticatedCustomerUser();
    }

    /**
     * Path of the sign-in page, carrying the page it is called from.
     *
     * Signing in interrupts what the visitor was doing, so the link says where to come
     * back to and the account pages stay the destination of last resort.
     */
    public function signInPath(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $route = $request?->attributes->get('_route');

        if (null === $request || \in_array($route, self::AUTHENTICATION_ROUTES, true)) {
            return $this->urlGenerator->generate('customer_login');
        }

        return $this->urlGenerator->generate('customer_login', [
            AuthenticationReturnUrl::PARAMETER => $request->getRequestUri(),
        ]);
    }

    /**
     * Current request locale in the language_TERRITORY form (fr_FR), which is what og:locale
     * expects — unlike lang_code, which carries the two-letter code alone.
     */
    public function currentLocale(): string
    {
        return $this->langService->getLocale() ?: 'en_US';
    }
}
