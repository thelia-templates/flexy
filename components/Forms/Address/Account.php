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

use FlexyBundle\Form\AddressEditForm;
use FlexyBundle\Service\CompanyAccount;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Domain\Addressing\Service\AddressAccess;
use Thelia\Domain\Addressing\Service\AddressService;
use Thelia\Domain\Customer\CustomerFacade;
use Thelia\Model\AddressQuery;

/**
 * Account variant of the address form. Unlike the checkout sibling it posts to a
 * controller instead of a LiveAction; the component only keeps the form live so
 * the country-dependent state field can rebuild itself.
 */
#[AsLiveComponent]
class Account
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public ?int $addressId = null;

    // Read by the Twig template on every render, including the internal ones a live model
    // update triggers: unlike addressId these never come from the browser, so they stay
    // constant across the component's lifetime rather than round-tripping user input.
    #[LiveProp]
    public string $action = '';

    #[LiveProp]
    public string $successUrl = '';

    #[LiveProp]
    public string $errorUrl = '';

    public function __construct(
        private readonly FormServiceInterface $formService,
        private readonly AddressService $addressService,
        private readonly CustomerFacade $customerFacade,
        private readonly AddressAccess $addressAccess,
        private readonly CompanyAccount $companyAccount,
    ) {
    }

    protected function instantiateForm(): FormInterface
    {
        $form = $this->formService->getFormByName(AddressEditForm::FORM_NAME, $this->getData());

        // The box only a buyer of a company has: sharing is with the company they belong to.
        if (!$this->companyAccount->isMember()) {
            $form->remove('share_with_company');
        }

        // A customer left with a single address cannot unset it as the default one:
        // the checkbox would offer a choice the shop cannot honour.
        if (null !== $this->addressId && $this->hasSingleAddress()) {
            $form->remove('is_default');
            $form->add('is_default', HiddenType::class, ['data' => 1]);
        }

        return $form;
    }

    /**
     * Scoped to what the session customer may change: the component must not rely on its
     * caller having checked, and the core `Get` on this resource lets whoever may use the
     * address read it. An address of the company the customer may not change is not offered
     * to them.
     *
     * @return array<string, mixed>
     */
    private function getData(): array
    {
        $customer = $this->customerFacade->getCurrentCustomer();

        if (null === $this->addressId || null === $customer) {
            return [];
        }

        $address = $this->addressAccess->find($customer, $this->addressId);

        if (null === $address || !$this->addressAccess->canEdit($customer, $address)) {
            return [];
        }

        return $this->addressService->mapModelToFormData($address) + ['share_with_company' => null !== $address->getCompanyId()];
    }

    /**
     * The addresses that can be the default one are the customer's own, never one a colleague shared.
     */
    private function hasSingleAddress(): bool
    {
        $customer = $this->customerFacade->getCurrentCustomer();

        if (null === $customer) {
            return false;
        }

        return AddressQuery::create()
            ->filterByCustomerId($customer->getId())
            ->count() <= 1;
    }
}
