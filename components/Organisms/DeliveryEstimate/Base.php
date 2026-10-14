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

namespace FlexyBundle\Components\Organisms\DeliveryEstimate;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Shipping\ShippingFacade;
use Thelia\Model\ProductQuery;

/**
 * The delivery a product page announces, for the destination of the visitor and named
 * after it. Nothing is shown when no carrier delivering there announces delays, on a core
 * that does not estimate deliveries yet, or for a virtual product.
 */
#[AsTwigComponent]
class Base
{
    public int $productId = 0;

    public function __construct(
        private readonly ShippingFacade $shippingFacade,
        private readonly CartFacade $cartFacade,
        private readonly LangService $langService,
    ) {
    }

    /**
     * @return array{earliest: string, latest: string, country: string}|null
     */
    public function getEstimate(): ?array
    {
        if (!method_exists($this->shippingFacade, 'estimateProductDelivery')) {
            return null;
        }

        $product = ProductQuery::create()->findPk($this->productId);

        if (null === $product) {
            return null;
        }

        // The cart of the session, never a new one: looking at a product creates no cart.
        $estimate = $this->shippingFacade->estimateProductDelivery($product, $this->cartFacade->getCartFromSession());

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
