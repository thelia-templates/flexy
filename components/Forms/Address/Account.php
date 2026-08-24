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
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Core\Form\FormServiceInterface;
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
    ) {
    }

    protected function instantiateForm(): FormInterface
    {
        $form = $this->formService->getFormByName(AddressEditForm::FORM_NAME, $this->getData());

        // A customer left with a single address cannot unset it as the default one:
        // the checkbox would offer a choice the shop cannot honour.
        if (null !== $this->addressId && $this->hasSingleAddress()) {
            $form->remove('is_default');
            $form->add('is_default', HiddenType::class, ['data' => 1]);
        }

        return $form;
    }

    /**
     * Scoped to the session customer: the component must not rely on its caller having
     * checked ownership, and the core `Get` on this resource carries no security
     * expression either.
     *
     * @return array<string, mixed>
     */
    private function getData(): array
    {
        $customer = $this->customerFacade->getCurrentCustomer();

        if (null === $this->addressId || null === $customer) {
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
