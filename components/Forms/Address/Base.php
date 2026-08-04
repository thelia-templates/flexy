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

namespace FlexyBundle\Components\Forms\Address;

use FlexyBundle\Event\CheckoutEvents;
use FlexyBundle\Form\AddressEditForm;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Core\Security\Front\FrontSecurityServiceInterface;
use Thelia\Domain\Addressing\Service\AddressService;
use Thelia\Model\AddressQuery;

#[AsLiveComponent]
class Base
{
    use ComponentToolsTrait;
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public ?int $addressId = null;

    public function __construct(
        private readonly FormServiceInterface $formService,
        private readonly AddressService $addressService,
        private readonly FrontSecurityServiceInterface $securityService,
    ) {
    }

    protected function instantiateForm(): FormInterface
    {
        $form = $this->formService->getFormByName(AddressEditForm::FORM_NAME, $this->getData());
        $form->remove('address3');
        $form->remove('company');

        return $form;
    }

    private function getData(): array
    {
        if (!$this->addressId) {
            return [];
        }

        $address = AddressQuery::create()->findPk($this->addressId);

        if (null === $address) {
            return [];
        }

        return $this->addressService->mapModelToFormData($address);
    }

    #[LiveAction]
    public function save(): void
    {
        if (!$this->securityService->isAuthenticatedFront()) {
            return;
        }

        $this->submitForm();

        if (!$this->getForm()->isValid()) {
            return;
        }

        $this->addressService->updateOrCreateAddress($this->addressId, $this->getForm());
        $this->emitUp(CheckoutEvents::ADD_NEW_DELIVERY_ADDRESS);
    }
}
