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

namespace FlexyBundle\Components\Organisms\CartButton;

use FlexyBundle\DTO\DisplayedPrice;
use FlexyBundle\Event\CheckoutEvents;
use FlexyBundle\Service\PriceDisplay;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Api\Service\DataAccess\AttributeAccessService;
use Thelia\Domain\Localization\Service\LangService;

#[AsLiveComponent]
class Base
{
    use DefaultActionTrait;

    /** @var array{itemCount: int, total: DisplayedPrice}|null */
    private ?array $summary = null;

    public function __construct(
        private readonly AttributeAccessService $attributeAccessService,
        private readonly LangService $langService,
        private readonly PriceDisplay $priceDisplay,
    ) {
    }

    /**
     * @return array{itemCount: int, total: DisplayedPrice}
     */
    #[LiveListener(CheckoutEvents::ADD_ITEM_EVENT)]
    #[LiveListener(CheckoutEvents::DELETE_ITEM_EVENT)]
    #[LiveListener(CheckoutEvents::UPDATE_ITEM_QUANTITY_EVENT)]
    #[LiveListener('syncSummary')]
    public function getSummary(): array
    {
        // Memoised: this doubles as the listener and as the template getter, so it is called
        // twice per live action and each read walks the cart to compute taxes.
        return $this->summary ??= [
            'itemCount' => (int) $this->attributeAccessService->attributeCart('item_count'),
            // Discounted, without postage: no delivery method is known outside the checkout.
            'total' => $this->priceDisplay->pick(
                $this->priceDisplay->forVisitor(),
                (float) $this->attributeAccessService->attributeCart('total_price_without_postage'),
                (float) $this->attributeAccessService->attributeCart('total_taxed_price_without_postage'),
            ),
        ];
    }

    /**
     * The `lang_code` Twig global is only assigned by the Thelia page parser, so it is
     * undefined when the component re-renders on the /_components endpoint.
     */
    public function getLocale(): ?string
    {
        return $this->langService->getLang()?->getLocale();
    }
}
