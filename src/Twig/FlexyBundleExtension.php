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

use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\Customer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class FlexyBundleExtension extends AbstractExtension
{
    public function __construct(
        private readonly SecurityContext $securityContext,
        private readonly LangService $langService,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('getCurrentCustomer', [$this, 'getCurrentCustomer']),
            new TwigFunction('current_locale', [$this, 'currentLocale']),
            new TwigFunction('hasCustomerAccount', [$this, 'hasCustomerAccount']),
        ];
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
     * Current request locale in the language_TERRITORY form (fr_FR), which is what og:locale
     * expects — unlike lang_code, which carries the two-letter code alone.
     */
    public function currentLocale(): string
    {
        return $this->langService->getLocale() ?: 'en_US';
    }
}
