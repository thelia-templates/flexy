<?php

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlexyBundle\Twig\Organisms\Modules;

use Propel\Runtime\Map\TableMap;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Log\Tlog;
use Thelia\Model\Customer;
use Thelia\Service\Model\AddressService;
use Thelia\Service\Model\CartService;

#[AsLiveComponent(template: '@components/Organisms/Modules/InvoiceAddresses/InvoiceAddresses.html.twig')]
class InvoiceAddresses extends BaseFrontController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public array $addresses = [];

    #[LiveProp(writable: true, onUpdated: 'setInvoiceOrderAddressId')]
    public ?int $invoiceAddressId = null;

    #[LiveProp]
    public ?int $update = null;
    #[LiveProp]
    public bool $create = false;
    #[LiveProp]
    public bool $switchView = false;

    public function __construct(
        private readonly Session $session,
        private readonly AddressService $addressService,
        private readonly CartService $cartService,
    ) {
    }

    public function mount(): void
    {
        /** @var Customer $user */
        $user = $this->session->getCustomerUser();
        $addresses = $user->getAddresses()?->toArray(null, false, TableMap::TYPE_CAMELNAME);

        $this->addresses = $addresses;
        $this->invoiceAddressId = $this->cartService->getCart()->getAddressInvoiceId();
        $this->switchView = !$this->invoiceAddressId;
    }

    #[LiveListener('InvoiceAddresses:refresh')]
    public function refresh(): void
    {
        $this->mount();
        $this->create = false;
        $this->update = null;
        $this->switchView = false;
    }

    #[LiveAction]
    public function newAddress(): void
    {
        $this->create = true;
    }

    #[LiveAction]
    public function switchAddress(): void
    {
        $this->switchView = !$this->switchView;
    }

    #[LiveAction]
    public function setInvoiceOrderAddressId(): void
    {
        $this->cartService->setInvoiceAddress($this->invoiceAddressId);
        $this->emit('resetCart');
    }

    #[LiveListener('cancelUpdateCreate')]
    public function cancelUpdateCreate(): void
    {
        $this->create = false;
        $this->update = null;
    }

    #[LiveListener('editAddress')]
    public function editAddress(#[LiveArg] int $id): void
    {
        $this->update = $id;
    }

    #[LiveListener('deleteAddress')]
    public function deleteAddress(#[LiveArg] int $id): void
    {
        $this->checkAuth();
        try {
            $this->addressService->deleteAddress($id);
            $this->refresh();
        } catch (\Exception $e) {
            Tlog::getInstance()->error(\sprintf('Error during address deletion : %s', $e->getMessage()));
        }
    }
}
