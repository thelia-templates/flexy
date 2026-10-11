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

namespace FlexyBundle\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Single source for the customer account navigation, shared by the account
 * subheader and the header profile dropdown.
 */
final readonly class AccountMenuService
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'translator')]
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<int, array{slug: string, text: string, href: string}>
     */
    public function getItems(): array
    {
        return [
            [
                'slug' => 'profile',
                'text' => $this->translator->trans('My profile'),
                'href' => $this->urlGenerator->generate('account_index'),
            ],
            [
                'slug' => 'orders',
                'text' => $this->translator->trans('My orders'),
                'href' => $this->urlGenerator->generate('account_orders'),
            ],
            [
                'slug' => 'addresses',
                'text' => $this->translator->trans('My addresses'),
                'href' => $this->urlGenerator->generate('account_addresses'),
            ],
        ];
    }
}
