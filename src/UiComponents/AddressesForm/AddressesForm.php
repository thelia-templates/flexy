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

namespace FlexyBundle\UiComponents\AddressesForm;

use FlexyBundle\Form\AddressEditForm;
use FlexyBundle\UiComponents\Checkout\CheckoutEvents;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Domain\Addressing\Service\AddressService;
use Thelia\Log\Tlog;
use Thelia\Model\AddressQuery;
use Thelia\Tools\URL;
use Thelia\Core\Form\FormServiceInterface;

#[AsLiveComponent(name: 'Flexy:AddressesForm', template: '@UiComponents/AddressesForm/AddressesForm.html.twig')]
class AddressesForm extends BaseFrontController
{
    use ComponentToolsTrait;
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public ?int $addressId = null;

    public ?string $action = '';

    public function __construct(
        private readonly FormServiceInterface $formService,
        private readonly AddressService $addressService,
    ) {
    }

    protected function instantiateForm(): FormInterface
    {

        $form = $this->formService->getFormByName(AddressEditForm::FORM_NAME, $this->getData());
        $form->remove('state');
        $form->remove('address3');
        $form->remove('company');

        return $form;
    }

    private function getData(): array
    {
        $customerId = $this->getSecurityContext()->getCustomerUser()?->getId();

        if (!$this->addressId || null === $customerId) {
            return [];
        }

        $address = AddressQuery::create()
            ->filterByCustomerId($customerId)
            ->findPk($this->addressId);

        if (null === $address) {
            return [];
        }

        return $this->addressService->mapModelToFormData($address);
    }

    #[LiveAction]
    public function save(): void
    {
        $this->checkAuth();

        $this->submitForm();
        if (!$this->getForm()->isValid()) {
            return;
        }
        $this->addressService->updateOrCreateAddress($this->addressId, $this->getForm());
        $this->emitUp(CheckoutEvents::ADD_NEW_DELIVERY_ADDRESS);
    }
}
