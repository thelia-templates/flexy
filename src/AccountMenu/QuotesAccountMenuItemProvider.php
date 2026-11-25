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

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Quote\Enum\QuoteMode;
use Thelia\Model\ConfigQuery;

/**
 * "My quotes", in the account of a shop that takes quotes.
 */
final readonly class QuotesAccountMenuItemProvider implements ShippedAccountMenuItemProviderInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'translator')]
        private TranslatorInterface $translator,
    ) {
    }

    public function getItems(): iterable
    {
        if (QuoteMode::Disabled === QuoteMode::fromConfig((string) ConfigQuery::read('quote_mode', QuoteMode::Disabled->value))) {
            return [];
        }

        return [
            new AccountMenuItem('quotes', $this->translator->trans('My quotes'), $this->urlGenerator->generate('account_quotes'), 220),
        ];
    }
}
