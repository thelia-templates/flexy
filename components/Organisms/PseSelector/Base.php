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

namespace FlexyBundle\Components\Organisms\PseSelector;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
class Base
{
    /**
     * What the core says about the price of the selected sale element for this visitor: who is
     * shown it (`priceDisplayMode`), whether it may be bought (`purchasable`), the tiers of the
     * price list that prices it, and whether the price itself is there. The sheet reads these
     * fields of the sale element the page selected and applies no rule of its own.
     *
     * A sale element without these fields (a caller that predates them) is read as the shop always
     * read it: its price, when it has one, is shown.
     *
     * @param array<string, mixed>|null $currentPse
     *
     * @return array{mode: string, purchasable: bool, showsPrice: bool, tiers: list<array<string, mixed>>}
     */
    public function priceFacts(?array $currentPse): array
    {
        $showsPrice = isset($currentPse['price']) || isset($currentPse['untaxedPrice']);

        if (null === $currentPse || !\array_key_exists('priceDisplayMode', $currentPse)) {
            return ['mode' => 'visible', 'purchasable' => true, 'showsPrice' => $showsPrice, 'tiers' => []];
        }

        return [
            'mode' => \is_string($currentPse['priceDisplayMode']) ? $currentPse['priceDisplayMode'] : 'visible',
            'purchasable' => (bool) ($currentPse['purchasable'] ?? true),
            'showsPrice' => $showsPrice,
            'tiers' => array_values((array) ($currentPse['priceTiers'] ?? [])),
        ];
    }

    /**
     * The price the `product.pse.alerts` hook hands to the modules (a price-drop alert, say): what the
     * buyer pays with its taxes, or null when the buyer is not shown a price. A module types it nullable.
     *
     * @param array<string, mixed>|null $currentPse
     */
    public function alertPrice(?array $currentPse, mixed $bundlePrice = null): mixed
    {
        if (!$this->priceFacts($currentPse)['showsPrice']) {
            return null;
        }

        if (null !== $bundlePrice) {
            return $bundlePrice;
        }

        return ($currentPse['isPromo'] ?? false) ? ($currentPse['promoPrice'] ?? null) : ($currentPse['price'] ?? null);
    }
}
