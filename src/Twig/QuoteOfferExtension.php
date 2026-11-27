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

use FlexyBundle\DTO\QuoteOfferView;
use FlexyBundle\Service\QuoteOffer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `quote_offer()`: what the shop offers about quotes for the cart in hand, for the pages of the cart.
 */
final class QuoteOfferExtension extends AbstractExtension
{
    public function __construct(private readonly QuoteOffer $quoteOffer)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('quote_offer', $this->offer(...)),
        ];
    }

    public function offer(): QuoteOfferView
    {
        return $this->quoteOffer->ofTheCart();
    }
}
