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

namespace FlexyBundle\Service;

use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Shipping\ShippingFacade;

/**
 * The delivery a product page announces, for the destination of the visitor and named
 * after it. Nothing when no carrier delivering there announces delays, on a core that does
 * not estimate deliveries yet, or for a virtual product.
 *
 * Not final: the product details are unit tested with a stub of it, the shipping facade it
 * reads being final itself.
 */
readonly class DeliveryEstimateResolver
{
    public function __construct(
        private ShippingFacade $shippingFacade,
        private CartFacade $cartFacade,
        private LangService $langService,
    ) {
    }

    /**
     * @return array{earliest: string, latest: string, country: string}|null
     */
    public function forProduct(int $productId): ?array
    {
        if (!method_exists($this->shippingFacade, 'estimateProductDeliveryById')) {
            return null;
        }

        // The cart of the session, never a new one: looking at a product creates no cart.
        $estimate = $this->shippingFacade->estimateProductDeliveryById($productId, $this->cartFacade->getCartFromSession());

        if (null === $estimate) {
            return null;
        }

        return [
            'earliest' => $estimate->estimate->earliestDay(),
            'latest' => $estimate->estimate->latestDay(),
            'country' => (string) $estimate->country->setLocale($this->langService->getLocale())->getTitle(),
        ];
    }
}
