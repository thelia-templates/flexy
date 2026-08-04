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

namespace FlexyBundle\UiComponents\AccountCustomerUpdate;

use FlexyBundle\Form\CustomerUpdateForm;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Domain\Addressing\Service\AddressService;
use Thelia\Domain\Customer\CustomerFacade;
use Thelia\Core\Form\FormServiceInterface;

#[AsLiveComponent(name: 'Flexy:AccountCustomerUpdate', template: '@UiComponents/AccountCustomerUpdate/AccountCustomerUpdate.html.twig')]
class AccountCustomerUpdate extends BaseFrontController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp()]
    public ?array $customer = null;

    public function __construct(
        private readonly AddressService $addressService,
        private readonly FormServiceInterface $formService,
        private readonly DataAccessService $dataAccessService,
        private readonly CustomerFacade $customerFacade,
    ) {
    }

    protected function instantiateForm(): FormInterface
    {
        // The template feeds this prop from resources(); if that call failed
        // the logged-in customer would silently get an empty profile form,
        // so fall back to the session customer.
        if (null === $this->customer && null !== $customer = $this->customerFacade->getCurrentCustomer()) {
            $this->customer = [
                'id' => $customer->getId(),
                'email' => $customer->getEmail(),
                'firstname' => $customer->getFirstname(),
                'lastname' => $customer->getLastname(),
            ];
        }

        return $this->formService->getFormByName(CustomerUpdateForm::FORM_NAME, $this->customer ?? []);
    }
}
