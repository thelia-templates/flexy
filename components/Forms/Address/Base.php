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
use FlexyBundle\Service\GuestCheckoutGate;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Domain\Addressing\Exception\AddressNotFoundException;
use Thelia\Domain\Addressing\Service\AddressAccess;
use Thelia\Domain\Addressing\Service\AddressService;
use Thelia\Domain\Customer\CustomerFacade;
use Thelia\Domain\Customer\Exception\CustomerException;

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
        private readonly CustomerFacade $customerFacade,
        private readonly GuestCheckoutGate $guestCheckoutGate,
        private readonly AddressAccess $addressAccess,
    ) {
    }

    protected function instantiateForm(): FormInterface
    {
        $form = $this->formService->getFormByName(AddressEditForm::FORM_NAME, $this->getData());

        // The box of the account address book: the checkout form has none.
        $form->remove('share_with_company');
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
     * Scoped to the checkout in hand: `addressId` reaches this component from the page,
     * and the core `Get` on this resource carries no security expression, so nothing
     * upstream guarantees the address belongs to whoever is asking. The customer row is
     * not enough either — a guest row is shared by everyone who ever ordered on that
     * email address — so the gate is asked, and an address it does not recognise opens
     * an empty form rather than showing what somebody else wrote.
     *
     * @return array<string, mixed>
     */
    private function getData(): array
    {
        $customer = $this->customerFacade->getCurrentCustomer();

        if (!$this->addressId || null === $customer) {
            return [];
        }

        if (!$this->guestCheckoutGate->isVisible($this->addressId)) {
            return [];
        }

        // The customer's own address, or one of their company that their role manages.
        $address = $this->addressAccess->find($customer, $this->addressId);

        if (null === $address || !$this->addressAccess->canEdit($customer, $address)) {
            return [];
        }

        return $this->addressService->mapModelToFormData($address);
    }

    /**
     * Any customer the session holds may write here, a guest checking out included: this
     * is the delivery step of the tunnel, and the address book it edits is the one the
     * core scopes to that very customer. What must stay closed to a guest is the account
     * area, which is guarded elsewhere.
     */
    #[LiveAction]
    public function save(): void
    {
        if (null === $this->customerFacade->getCurrentCustomer()) {
            return;
        }

        // Editing writes over whatever `addressId` names, so the same scope that decides
        // what may be read decides what may be overwritten. A creation names nothing yet.
        if (null !== $this->addressId) {
            $this->guestCheckoutGate->assertVisible($this->addressId);
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
