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

namespace FlexyBundle\Components\Organisms\NextButton;

use FlexyBundle\Components\Molecules\CheckoutSteps\Base as CheckoutSteps;
use FlexyBundle\Event\CheckoutEvents;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Legal\CompanyIdentifierRules;
use Thelia\Model\Cart;
use Thelia\Model\CartAddressQuery;

#[AsLiveComponent]
class Base
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp(updateFromParent: true)]
    public int $step;

    #[LiveProp(updateFromParent: true)]
    public string $href;

    public function __construct(
        private readonly CartFacade $cartFacade,
    ) {
    }

    public function mount(int $step, string $href): void
    {
        $this->step = $step;
        $this->href = $href;
    }

    #[LiveListener(CheckoutEvents::DELETE_ITEM_EVENT)]
    #[LiveListener(CheckoutEvents::ADD_ITEM_EVENT)]
    #[LiveListener(CheckoutEvents::SET_PAYMENT_MODULE_ID)]
    #[LiveListener(CheckoutEvents::SET_INVOICE_ORDER_ADDRESS_ID)]
    #[LiveListener('updateNextButton')]
    public function getIsValid(): bool
    {
        return match ($this->step) {
            CheckoutSteps::CART => $this->isCartValid(),
            CheckoutSteps::DELIVERY => $this->isDeliveryValid(),
            CheckoutSteps::PAYMENT => $this->isPaymentValid(),
            default => false,
        };
    }

    private function isCartValid(): bool
    {
        return $this->cartFacade->getOrCreateFromSession()->countCartItems() > 0;
    }

    private function isDeliveryValid(): bool
    {
        $cart = $this->cartFacade->getOrCreateFromSession();

        return $this->isCartValid()
            && $cart->getAddressDeliveryId()
            && $cart->getDeliveryModuleId();
    }

    private function isPaymentValid(): bool
    {
        $cart = $this->cartFacade->getOrCreateFromSession();

        return $this->isDeliveryValid()
            && $cart->getPaymentModuleId()
            && $cart->getAddressInvoiceId()
            && $this->hasBillableInvoiceAddress($cart);
    }

    /**
     * Greys out the button rather than letting the buyer submit and bounce back: an invoice for
     * a business needs its legal identifiers. CheckoutValidationService holds the same rule and
     * is the one that decides - this only spares a round trip.
     */
    private function hasBillableInvoiceAddress(Cart $cart): bool
    {
        $address = CartAddressQuery::create()->findPk($cart->getAddressInvoiceId());

        if (null === $address) {
            return false;
        }

        return [] === CompanyIdentifierRules::violationsFor(
            $address->getCompany(),
            $address->getSiret(),
            $address->getVatNumber(),
            $address->getCountry()?->getIsoalpha2(),
        );
    }
}
