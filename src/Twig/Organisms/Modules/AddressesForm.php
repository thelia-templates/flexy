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

use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Core\Event\Address\AddressCreateOrUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Form\Definition\FrontForm;
use Thelia\Model\Customer;
use TwigEngine\Service\FormService;

#[AsLiveComponent(template: '@components/Organisms/Modules/HomeDelivery/AddressesForm.html.twig')]
class AddressesForm
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public ?int $addressId = null;

    public function __construct(
        private readonly FormService $formService,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly Session $session
    ) {
    }

    protected function instantiateForm(): FormInterface
    {
        $formName = $this->addressId ? FrontForm::ADDRESS_CREATE : FrontForm::ADDRESS_CREATE;
        $form = $this->formService->getFormByName($formName, [
        ]);
        /* TODO : virer ça dans le core Thelia plutôt que ici */
        $form->remove('state');
        $form->remove('address3');
        $form->remove('company');

        return $form;
    }

    #[LiveAction]
    public function save(): void
    {
        // Submit the form! If validation fails, an exception is thrown
        // and the component is automatically re-rendered with the errors
        $this->submitForm();
        if ($this->getForm()->isValid()) {
            /** @var Customer $user */
            $user = $this->session->getCustomerUser();

            $event = $this->createAddressEvent($this->formValues);
            $event->setCustomer($user);

            $this->dispatcher->dispatch($event, TheliaEvents::ADDRESS_CREATE);
        }
        $this->getForm();
    }

    private function createAddressEvent($data)
    {
        return new AddressCreateOrUpdateEvent(
            $data['label'],
            null,
            $data['firstname'],
            $data['lastname'],
            $data['address1'],
            $data['address2'],
            '',
            $data['zipcode'],
            $data['city'],
            $data['country'],
            null,
            $data['phone'],
            null,
            false
        );
    }
}
