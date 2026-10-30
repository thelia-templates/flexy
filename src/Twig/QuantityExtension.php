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
use Thelia\Domain\Localization\Service\LangService;
use Twig\Extension\AbstractExtension;
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
