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
use Propel\Runtime\Map\TableMap;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Checkout\DTO\CheckoutDTO;
use Thelia\Model\Customer;

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
        private readonly Session $session,
    ) {
    }

    public function mount(): void
    {
        $this->invoiceAddressId = $this->cartFacade->getInvoiceAddressId();
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

    public function getAddressList(): array
    {
        /** @var Customer|null $user */
        $user = $this->session->getCustomerUser();

        return $user?->getAddresses()->toArray(null, false, TableMap::TYPE_CAMELNAME) ?? [];
    }

    #[LiveListener(CheckoutEvents::SET_INVOICE_ORDER_ADDRESS_ID)]
    public function selectInvoiceAddress(#[LiveArg] ?int $addressId): void
    {
        $this->cartFacade->setInvoiceAddress(new CheckoutDTO(
            cart: $this->cartFacade->getOrCreateFromSession(),
            invoiceAddressId: $addressId,
        ));
        $this->invoiceAddressId = $this->cartFacade->getInvoiceAddressId();

        $this->emit('hideShowAddressList');
        $this->emit('updateNextButton');
    }
}
