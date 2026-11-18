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

namespace FlexyBundle\Components\Molecules\CountrySuggestion;

use FlexyBundle\Components\Organisms\LangSelect\Base as LangSelect;
use FlexyBundle\Service\ShopPriceDisplay;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Localization\VisitorCountry\IsoCountryCode;
use Thelia\Domain\Localization\VisitorCountryFacade;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Country;
use Thelia\Model\CountryI18nQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;

/**
 * Offers the visitor the country the shop detected for them.
 *
 * The core detects once per session and remembers the answer: this only reads the
 * suggestion, so there is nothing to show once the visitor answered, and nothing in the
 * checkout, where a change of country or of domain would start a second cart.
 */
#[AsTwigComponent]
class Base
{
    private bool $resolved = false;

    private ?Country $suggestion = null;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Session $session,
        private readonly LangSelect $langSelect,
        // Optional: the facade exists only in a core that carries the detection, and the CI of the theme installs the published one.
        private readonly ?VisitorCountryFacade $visitorCountry = null,
        private readonly ?ShopPriceDisplay $shopPriceDisplay = null,
    ) {
    }

    /**
     * Not on a shop that does not show its prices to the visitor: the banner offers to see the
     * prices of their country.
     */
    public function isOffered(): bool
    {
        return false !== $this->shopPriceDisplay?->showsPrices() && null !== $this->suggestion();
    }

    public function getCountryId(): int
    {
        return (int) $this->suggestion()?->getId();
    }

    /**
     * The name of the country in the language of the visitor, the first one that has a title.
     */
    public function getCountryName(): string
    {
        $country = $this->suggestion();

        if (!$country instanceof Country) {
            return '';
        }

        // Read from the table, not through setLocale() on the model: the instance is shared
        // through the Propel pool and would stay in the language read.
        $locales = array_unique([
            $this->session->getLang()?->getLocale() ?? Lang::getDefaultLanguage()->getLocale(),
            Lang::getDefaultLanguage()->getLocale(),
        ]);

        foreach ($locales as $locale) {
            $title = CountryI18nQuery::create()->filterById($country->getId())->filterByLocale($locale)->findOne()?->getTitle();

            if (null !== $title && '' !== $title) {
                return $title;
            }
        }

        return (string) $country->getIsoalpha2();
    }

    /**
     * When the shop has one domain per language and one of its languages is the one of the
     * detected country (`nl_BE` for BE) on another domain, accepting is a visit to that
     * domain: the page being read, asked for in that language, as Organisms/LangSelect does.
     * The core redirects to the domain of the language from there.
     */
    public function getSwitchUrl(): ?string
    {
        $country = $this->suggestion();

        if (!$country instanceof Country || !ConfigQuery::isMultiDomainActivated()) {
            return null;
        }

        $currentLocale = $this->session->getLang()?->getLocale();
        $isoCode = IsoCountryCode::normalize($country->getIsoalpha2());
        $switchUrls = $this->langSelect->getSwitchUrls();

        // In a fixed order: with two languages of the country, the same one is offered on every page.
        foreach (LangQuery::create()->filterByActive(1)->filterByVisible(1)->orderByPosition()->orderById()->find() as $lang) {
            $locale = (string) $lang->getLocale();

            if (
                $locale !== $currentLocale
                && '' !== (string) $lang->getUrl()
                && null !== $isoCode
                && IsoCountryCode::ofLocale($locale) === $isoCode
                && isset($switchUrls[$locale])
            ) {
                return $switchUrls[$locale];
            }
        }

        return null;
    }

    private function suggestion(): ?Country
    {
        if ($this->resolved) {
            return $this->suggestion;
        }

        $this->resolved = true;

        if (
            !class_exists(VisitorCountryFacade::class)
            || null === $this->visitorCountry
            || $this->isInTheCheckout()
        ) {
            return null;
        }

        return $this->suggestion = $this->visitorCountry->suggestion();
    }

    private function isInTheCheckout(): bool
    {
        $attributes = $this->requestStack->getMainRequest()?->attributes;

        return str_starts_with((string) $attributes?->get('_route', ''), 'checkout_')
            || str_starts_with((string) $attributes?->get('_view', ''), 'checkout');
    }
}
