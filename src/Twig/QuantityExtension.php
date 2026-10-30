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

use FlexyBundle\DTO\QuantityRuleDTO;
use FlexyBundle\Service\ReferenceUnitPrices;
use Thelia\Domain\Catalog\Quantity\QuantityFormatter;
use Thelia\Domain\Catalog\Quantity\UnitCode;
use Thelia\Domain\Localization\Service\LangService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Quantities and the prices that go with them, in the language of the session: the Twig
 * global `lang_code` is assigned by the page parser only, and a component re-rendered on its
 * own has none.
 */
final class QuantityExtension extends AbstractExtension
{
    public function __construct(
        private readonly ReferenceUnitPrices $referenceUnitPrices,
        private readonly LangService $langService,
    ) {
    }

    public function getFilters(): array
    {
        return [
            // {{ quantity|format_quantity(decimals, unitCode) }}: "1,25 kg" in French, "6" for six pieces.
            new TwigFilter('format_quantity', $this->formatQuantity(...)),
        ];
    }

    /**
     * Written by the core (QuantityFormatter), each theme only declaring its filter. A core
     * without quantity rules has no formatter and no units: the number alone, with its decimals.
     */
    public function formatQuantity(float|int|string|null $quantity, int|string|null $decimals = 0, ?string $unitCode = null): string
    {
        $quantity = is_numeric($quantity) ? (float) $quantity : 0.0;
        $decimals = max(0, min(3, (int) $decimals));
        $locale = $this->langService->getLocale();

        if (class_exists(QuantityFormatter::class)) {
            return (new QuantityFormatter())->format($quantity, $decimals, UnitCode::tryFrom((string) $unitCode), $locale);
        }

        $formatter = new \NumberFormatter($locale ?? 'en_US', \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        return $formatter->format($quantity) ?: number_format($quantity, $decimals, '.', '');
    }

    public function getFunctions(): array
    {
        return [
            // {{ reference_unit_price(displayedPrice, quantityRule) }}: {price, per} or null.
            new TwigFunction('reference_unit_price', fn (float|int|string|null $displayedPrice, ?QuantityRuleDTO $rule): ?array => $this->referenceUnitPrices->of(
                is_numeric($displayedPrice) ? (float) $displayedPrice : null,
                $rule,
                $this->langService->getLocale(),
            )),
        ];
    }
}
