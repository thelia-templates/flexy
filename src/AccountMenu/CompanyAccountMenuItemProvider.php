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

namespace FlexyBundle\AccountMenu;

use FlexyBundle\Service\CompanyAccount;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "My company", after "My addresses", for a buyer who belongs to a company. Decided here, from
 * the shop, and not by a test in the templates: a customer with no company has no such page,
 * and no such link.
 */
final readonly class CompanyAccountMenuItemProvider implements ShippedAccountMenuItemProviderInterface
{
    public function __construct(
        private CompanyAccount $companyAccount,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'translator')]
        private TranslatorInterface $translator,
    ) {
    }

    public function getItems(): iterable
    {
        if (!$this->companyAccount->isMember()) {
            return [];
        }

        return [
            new AccountMenuItem('company', $this->translator->trans('My company'), $this->urlGenerator->generate('account_company'), 350),
        ];
    }
}
