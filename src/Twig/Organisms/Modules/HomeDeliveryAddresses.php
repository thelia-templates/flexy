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
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Service\AddressService;
use Thelia\Log\Tlog;
use Thelia\Model\Customer;

#[AsLiveComponent(template: '@components/Organisms/Modules/HomeDelivery/HomeDeliveryAddresses.html.twig')]
class HomeDeliveryAddresses extends BaseFrontController
{
    use DefaultActionTrait;

    #[LiveProp]
    public array $addresses = [];
    #[LiveProp]
    public ?int $update = null;
    #[LiveProp]
    public bool $create = false;

    public function __construct(
        private readonly Session $session,
        private readonly AddressService $addressService,
    ) {
    }

    public function mount(): void
    {
        /** @var Customer $user */
        $user = $this->session->getCustomerUser();
        $addresses = $user->getAddresses()?->toArray(null, false, TableMap::TYPE_CAMELNAME);

        $this->addresses = $addresses;
    }

    #[LiveListener('homeDeliveryAddresses:refresh')]
    public function refresh(): void
    {
        $this->mount();
        $this->create = false;
        $this->update = null;
    }

    #[LiveAction]
    public function newAddress(): void
    {
        $this->create = true;
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
            Tlog::getInstance()->error(sprintf('Error during address deletion : %s', $e->getMessage()));
        }
    }
}
