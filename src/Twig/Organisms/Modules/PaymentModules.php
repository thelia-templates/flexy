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

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Service\Model\AddressService;
use Thelia\Service\Model\CartService;
use TwigEngine\Service\DataAccess\DataAccessService;

#[AsLiveComponent(template: '@components/Organisms/Modules/Payment/PaymentModules.html.twig')]
class PaymentModules extends BaseFrontController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp()]
    public ?array $modules = [];

    #[LiveProp(writable: true, onUpdated: 'setCartPaymentModuleId')]
    public ?int $paymentModuleId = null;

    #[LiveProp]
    public ?int $invoiceAddressId = null;

    public function __construct(
        private readonly Session        $session,
        private readonly AddressService $addressService,
        private readonly CartService    $cartService,
        private DataAccessService       $dataAccessService,
    )
    {
    }

    public function mount(): void
    {
        $this->modules = $this->dataAccessService->resources('/api/front/payment/modules');
        $this->invoiceAddressId = $this->cartService->getCart()->getAddressInvoiceId();
    }

    #[LiveAction]
    public function setCartPaymentModuleId(): void
    {
        if ($this->paymentModuleId) {
            $this->cartService->setPaymentModule($this->paymentModuleId);
            $this->emit('resetCart');
        }
    }
}
