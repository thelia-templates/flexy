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
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Service\AddressService;
use Thelia\Form\Definition\FrontForm;
use Thelia\Log\Tlog;
use Thelia\Model\AddressQuery;
use TwigEngine\Service\FormService;

#[AsLiveComponent(template: '@components/Organisms/Modules/HomeDelivery/AddressesForm.html.twig')]
class AddressesForm extends BaseFrontController
{
    use ComponentToolsTrait;
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public ?int $addressId = null;

    public function __construct(
        private readonly FormService $formService,
        private readonly AddressService $addressService
    ) {
    }

    protected function instantiateForm(): FormInterface
    {
        $formName = $this->addressId ? FrontForm::ADDRESS_UPDATE : FrontForm::ADDRESS_CREATE;

        $form = $this->formService->getFormByName($formName, $this->getData());
        $form->remove('state');
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

        return $this->addressService->mapModelToFormData($address);
    }

    #[LiveAction]
    public function save(): void
    {
        $this->checkAuth();
        // Submit the form! If validation fails, an exception is thrown
        // and the component is automatically re-rendered with the errors
        $this->submitForm();
        if (!$this->getForm()->isValid()) {
            return;
        }
        try {
            $this->addressService->updateOrCreateAddress($this->addressId, $this->getForm());
            $this->emitUp('homeDeliveryAddresses:refresh');
        } catch (\Exception $e) {
            Tlog::getInstance()->error(sprintf('Error during address creation process : %s', $e->getMessage()));
        }
    }
}
