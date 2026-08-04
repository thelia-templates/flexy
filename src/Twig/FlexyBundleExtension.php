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

use FlexyBundle\Service\ProductSaleElementsService;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\Customer;
use Thelia\Model\ProductSaleElements;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class FlexyBundleExtension extends AbstractExtension
{
    public function __construct(
        private ProductSaleElementsService $pseService,
        private SecurityContext $securityContext,
        private LangService $langService,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('attributeAv', [$this, 'attributeAv']),
            new TwigFunction('getCurrentCustomer', [$this, 'getCurrentCustomer']),
            new TwigFunction('current_locale', [$this, 'currentLocale']),
        ];
    }

    /**
     * Current request locale in the language_TERRITORY form (e.g. fr_FR),
     * as expected by og:locale.
     */
    public function currentLocale(): string
    {
        return $this->langService->getLocale() ?: 'en_US';
    }

    public function getCurrentCustomer(): ?Customer
    {
        return $this->securityContext->getCustomerUser();
    }

    public function attributeAv(?ProductSaleElements $pse): array
    {
        if (null === $pse) {
            return [];
        }

        return $this->pseService->getAttributesAvFromPse($pse);
    }
}
