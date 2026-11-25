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

use FlexyBundle\DTO\QuoteReplyView;
use FlexyBundle\Service\QuoteReply;
use Thelia\Core\Security\SecurityContext;
use Thelia\Model\Customer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `quote_reply(quoteId)`: what the buyer in hand may answer a quote with, for the page of the quote.
 */
final class QuoteReplyExtension extends AbstractExtension
{
    public function __construct(
        private readonly QuoteReply $quoteReply,
        private readonly SecurityContext $securityContext,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('quote_reply', $this->reply(...)),
        ];
    }

    public function reply(int $quoteId): QuoteReplyView
    {
        $customer = $this->securityContext->getCustomerUser();

        return $this->quoteReply->offeredFor($customer instanceof Customer ? $customer : null, $quoteId);
    }
}
