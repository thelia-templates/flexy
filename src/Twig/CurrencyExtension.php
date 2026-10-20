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
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Currency;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class CurrencyExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_currency_code', [$this, 'currentCurrencyCode']),
        ];
    }

    /**
     * ISO code of the currency the visitor browses in: the one of the session, which the core
     * sets from ?currency= and in which the API already converts the prices. The amounts of a
     * placed order do not come here, they keep the currency the order was placed in.
     */
    public function currentCurrencyCode(): string
    {
        $session = $this->requestStack->getCurrentRequest()?->getSession();
        $currency = $session instanceof Session ? $session->getCurrency() : Currency::getDefaultCurrency();

        return $currency->getCode() ?: 'EUR';
    }
}
