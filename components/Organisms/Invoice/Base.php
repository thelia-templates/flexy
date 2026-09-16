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

namespace FlexyBundle\Components\Organisms\Invoice;

use FlexyBundle\Event\CheckoutEvents;
use FlexyBundle\Service\BillingAddressChoice;
use FlexyBundle\Service\GuestCheckoutGate;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Checkout\DTO\CheckoutDTO;

#[AsLiveComponent]
class Base
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp(updateFromParent: true)]
    public ?int $invoiceAddressId = null;

    public bool $showNewAddressForm = false;

    #[LiveProp]
    public bool $showAddressList = false;

    #[LiveProp]
    public ?int $editingAddressId = null;

    public function __construct(
        private readonly CartFacade $cartFacade,
        private readonly GuestCheckoutGate $guestCheckoutGate,
    ) {
    }

    public function mount(): void
    {
        $this->invoiceAddressId = $this->cartFacade->getInvoiceAddressId();

        if (null === $this->invoiceAddressId) {
            $this->preselectBillingAddress();
        }
    }

    /**
     * Bills the buyer where the order ships, and failing that on an address of their book.
     *
     * The delivery step already picks the default address for the buyer, and until this
     * the payment step was the one place they had to choose an address a second time: the
     * cart kept no billing address, the core refuses an order without one (CartGuard), and
     * the Order button therefore stayed grey with nothing on screen saying why.
     *
     * Which address is chosen is {@see BillingAddressChoice}; the card above the payment
     * methods then shows it, and the buyer changes it from that same card.
     */
    private function preselectBillingAddress(): void
    {
        $addressId = BillingAddressChoice::from(
            $this->getAddressList(),
            $this->cartFacade->getDeliveryAddressId(),
        );

        if (null !== $addressId) {
            $this->billTo($addressId);
        }
    }

    #[LiveListener(CheckoutEvents::ADD_NEW_DELIVERY_ADDRESS)]
    #[LiveListener('cancelAddressForm')]
    public function resetAddressForm(): void
    {
        $this->showNewAddressForm = false;
        $this->editingAddressId = null;
        $this->showAddressList = false;
    }

    #[LiveAction]
    public function toggleNewAddressForm(): void
    {
        $this->showNewAddressForm = !$this->showNewAddressForm;
    }

    #[LiveListener(CheckoutEvents::EDIT_INVOICE_ADDRESS)]
    public function setEditingAddress(#[LiveArg] int $addressId): void
    {
        $this->guestCheckoutGate->assertVisible($addressId);

        $this->editingAddressId = $addressId;
    }

    #[LiveListener('toggleShowAddressList')]
    public function toggleShowAddressList(): void
    {
        $this->showAddressList = !$this->showAddressList;
    }

    #[LiveListener('hideShowAddressList')]
    public function hideShowAddressList(): void
    {
        $this->showAddressList = false;
    }

    /**
     * The address book of whoever is checking out — never the whole of the row's own.
     *
     * A guest shares their customer row with everyone who ever ordered on that address,
     * so the gate narrows the list down to the addresses of this identification. A
     * signed-in customer gets theirs, whole.
     */
    public function getAddressList(): array
    {
        return $this->guestCheckoutGate->visibleAddresses();
    }

    #[LiveListener(CheckoutEvents::SET_INVOICE_ORDER_ADDRESS_ID)]
    public function selectInvoiceAddress(#[LiveArg] ?int $addressId): void
    {
        // Null is "no billing address yet", and names none to check. The order is refused
        // without one: nothing but the buyer clearing their choice arrives here with null.
        if (null !== $addressId) {
            $this->guestCheckoutGate->assertVisible($addressId);
        }

        $this->billTo($addressId);

        $this->emit('hideShowAddressList');
        $this->emit('updateNextButton');
    }

    /**
     * Writes the billing address on the cart and reads back what the cart kept.
     *
     * Apart from the emits, which belong to a buyer's click and not to a first render,
     * this is the whole of what choosing a billing address does — mount and the live
     * listener go through it alike.
     */
    private function billTo(?int $addressId): void
    {
        $this->cartFacade->setInvoiceAddress(new CheckoutDTO(
            cart: $this->cartFacade->getOrCreateFromSession(),
            invoiceAddressId: $addressId,
        ));

        $this->invoiceAddressId = $this->cartFacade->getInvoiceAddressId();
    }
}
