<?php

namespace FlexyBundle\Twig\Organisms;

use FlexyBundle\Form\CheckoutForm;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Service\Model\CartService;
use Thelia\Service\Model\CheckoutService;

#[AsLiveComponent(template: '@components/Organisms/OrderCreator/OrderCreator.html.twig')]
class OrderCreator extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;
    use ComponentToolsTrait;

    public function __construct(
        private readonly CartService     $cartService,
        private readonly CheckoutService $checkoutService,
    )
    {
    }

    protected function instantiateForm(): FormInterface
    {
        $cart = $this->cartService->getCart();

        $data = [
            'delivery-module-id' => $cart?->getDeliveryModuleId(),
            'payment-module-id' => $cart?->getPaymentModuleId(),
            'delivery-address-id' => $cart?->getAddressDeliveryId(),
            'invoice-address-id' => $cart?->getAddressInvoiceId(),
        ];

        return $this->createForm(CheckoutForm::class, $data);
    }

    #[LiveAction]
    public function save(): ?Response
    {
        $this->submitForm();

        if ($this->getForm()->isValid()) {
            $formData = $this->getForm()->getData();

            $response = $this->checkoutService->pay(
                deliveryAddressId: $formData['delivery-address-id'],
                invoiceAddressId: $formData['invoice-address-id'],
                deliveryModuleId: $formData['delivery-module-id'],
                paymentModuleId: $formData['payment-module-id']
            );

            if ($response instanceof Response && $response->getStatusCode() === 200) {
                return $response;
            }

            $this->emit('navigateToConfirm');
        }

        return null;
    }
}
