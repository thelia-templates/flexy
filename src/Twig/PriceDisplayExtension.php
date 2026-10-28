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

use FlexyBundle\DTO\DisplayedPrice;
use FlexyBundle\Service\PriceDisplay;
use FlexyBundle\Service\TaxDisplay;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The templates' door onto PriceDisplay: they ask which display applies, hand it the figures
 * the core published, and print what comes back with the mention component. None of them
 * chooses between a taxed and an untaxed amount on its own.
 */
class PriceDisplayExtension extends AbstractExtension
{
    public function __construct(
        private readonly PriceDisplay $priceDisplay,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('tax_mention', [$this, 'taxMention']),
        ];
    }

    /**
     * The mention of a figure, translated, as plain text: for the places that write it inside
     * a sentence or an attribute (a label, an aria-valuetext), where the mention component
     * and its markup cannot go. One wording for every place a price is told.
     */
    public function taxMention(TaxDisplay|DisplayedPrice|string $of): string
    {
        $display = match (true) {
            $of instanceof DisplayedPrice => $of->display,
            $of instanceof TaxDisplay => $of,
            default => TaxDisplay::from($of),
        };

        return $this->translator->trans($display->mention());
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('visitor_tax_display', [$this, 'visitorTaxDisplay']),
            new TwigFunction('displayed_price', [$this, 'displayedPrice']),
            new TwigFunction('order_total', [$this, 'orderTotal']),
        ];
    }

    public function visitorTaxDisplay(): TaxDisplay
    {
        return $this->priceDisplay->forVisitor();
    }

    /**
     * What a placed order cost, in the display it was placed under.
     *
     * @param array<string, mixed> $order
     */
    public function orderTotal(array $order): DisplayedPrice
    {
        return $this->priceDisplay->orderTotal($this->priceDisplay->forOrder($order), $order);
    }

    public function displayedPrice(TaxDisplay $display, ?float $untaxed, ?float $taxed): ?DisplayedPrice
    {
        return $this->priceDisplay->choose($display, $untaxed, $taxed);
    }
}
