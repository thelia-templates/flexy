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

namespace FlexyBundle\Components\Organisms\CurrencySelect;

use FlexyBundle\Twig\CurrencyExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Api\Service\DataAccess\DataAccessService;

/**
 * Lets the visitor browse in another currency: the core switches the currency of the session
 * on ?currency=<code> and the API prices follow. Drawn only when there is a choice.
 */
#[AsTwigComponent]
class Base
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $currencies = null;

    public function __construct(
        private readonly DataAccessService $dataAccessService,
        private readonly CurrencyExtension $currencyExtension,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCurrencies(): array
    {
        return $this->currencies ??= $this->dataAccessService->resources('/api/front/currencies', [
            'visible' => true,
        ]) ?? [];
    }

    /**
     * The currency being browsed in, null when the shop offers no choice.
     *
     * @return array<string, mixed>|null
     */
    public function getCurrentCurrency(): ?array
    {
        $currencies = $this->getCurrencies();

        if (\count($currencies) <= 1) {
            return null;
        }

        $code = $this->currencyExtension->currentCurrencyCode();

        foreach ($currencies as $currency) {
            if (($currency['code'] ?? null) === $code) {
                return $currency;
            }
        }

        return null;
    }

    /**
     * Where each currency sends the visitor, keyed by code: the page being read with the
     * currency asked for. The query string comes from the request itself, not from
     * app.request.query, which the rewriting router fills with its own parameters.
     *
     * @return array<string, string>
     */
    public function getSwitchUrls(): array
    {
        $request = $this->requestStack->getMainRequest();

        if (!$request instanceof Request) {
            return [];
        }

        parse_str((string) $request->getQueryString(), $query);
        unset($query['currency']);

        $path = $request->getBaseUrl().$request->getPathInfo();
        $switchUrls = [];

        foreach ($this->getCurrencies() as $currency) {
            $code = $currency['code'] ?? null;

            if (\is_string($code) && '' !== $code) {
                $switchUrls[$code] = $path.'?'.http_build_query($query + ['currency' => $code]);
            }
        }

        return $switchUrls;
    }
}
