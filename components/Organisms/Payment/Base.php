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

namespace FlexyBundle\Components\Organisms\Payment;

use FlexyBundle\Event\CheckoutEvents;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Checkout\DTO\CheckoutDTO;

#[AsLiveComponent]
class Base
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public ?int $paymentModuleId = null;

    #[LiveProp]
    public ?int $invoiceAddressId = null;

    public function __construct(
        private readonly DataAccessService $dataAccessService,
        private readonly CartFacade $cartFacade,
    ) {
    }

    public function mount(): void
    {
        $this->invoiceAddressId = $this->cartFacade->getInvoiceAddressId();
        $this->paymentModuleId = $this->cartFacade->getPaymentModuleId();
    }

    public function getModules(): array
    {
        return $this->dataAccessService->resources('/api/front/payment/modules');
    }

    #[LiveListener(CheckoutEvents::SET_PAYMENT_MODULE_ID)]
    public function selectPaymentModuleId(#[LiveArg] int $moduleId): void
    {
        $this->cartFacade->setPaymentModule(new CheckoutDTO(
            cart: $this->cartFacade->getOrCreateFromSession(),
            paymentModuleId: $moduleId,
        ));

        $this->paymentModuleId = $this->cartFacade->getPaymentModuleId();

        $this->emit('updateNextButton');
    }
}
