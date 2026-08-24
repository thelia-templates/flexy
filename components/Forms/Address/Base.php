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
use Thelia\Domain\Addressing\Exception\AddressNotFoundException;
use Thelia\Domain\Addressing\Service\AddressService;
use Thelia\Domain\Customer\Exception\CustomerException;
use Thelia\Domain\Customer\CustomerFacade;
use Thelia\Model\AddressQuery;

#[AsLiveComponent]
class Base
{
    use ComponentToolsTrait;
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public ?int $addressId = null;

    /**
     * The checkout asks for a company name on the billing address only, and the legal
     * identifiers come with it: a delivery address never carries any of the three.
     */
    #[LiveProp]
    public bool $withCompany = false;

    public function __construct(
        private readonly FormServiceInterface $formService,
        private readonly AddressService $addressService,
        private readonly FrontSecurityServiceInterface $securityService,
        private readonly CustomerFacade $customerFacade,
    ) {
    }

    protected function instantiateForm(): FormInterface
    {
        $form = $this->formService->getFormByName(AddressEditForm::FORM_NAME, $this->getData());
        $form->remove('address3');

        if (!$this->withCompany) {
            $form->remove('company');
            // The identifiers are dependent fields, so they are only there when a company
            // name was submitted; removing them unconditionally would fail on the others.
            foreach (['siret', 'vat_number'] as $field) {
                if ($form->has($field)) {
                    $form->remove($field);
                }
            }
        }

        return $form;
    }

    /**
     * Scoped to the session customer: `addressId` reaches this component from the page,
     * and the core `Get` on this resource carries no security expression, so nothing
     * upstream guarantees the address belongs to whoever is asking.
     *
     * @return array<string, mixed>
     */
    private function getData(): array
    {
        $customer = $this->customerFacade->getCurrentCustomer();

        if (!$this->addressId || null === $customer) {
            return [];
        }

        $address = AddressQuery::create()
            ->filterByCustomerId($customer->getId())
            ->findPk($this->addressId)
        ;

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

        try {
            $this->addressService->updateOrCreateAddress($this->addressId, $this->getForm());
        } catch (AddressNotFoundException|CustomerException) {
            // The address was not the customer's: fail closed rather than 500 the component.
            return;
        }

        $this->emitUp(CheckoutEvents::ADD_NEW_DELIVERY_ADDRESS);
    }
}
