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

namespace FlexyBundle\Twig\Organisms;

use FlexyBundle\Form\CustomerActivationForm;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Service\Model\CustomerService;

#[AsLiveComponent(template: '@components/Organisms/RegisterValidationCode/RegisterValidationCode.html.twig')]
class RegisterValidationCode extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;
    public const CODE_CHARSETS_COUNT = 6;

    public ?int $nbChars = 0;

    public function __construct(protected CustomerService $customerService)
    {
    }

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(CustomerActivationForm::class);
    }

    #[LiveAction]
    public function save(): void
    {
        $this->submitForm();

        if ($this->getForm()->isValid()) {
            $formData = $this->getForm()->getData();
            $this->customerService->customerActivationByCode($formData['customer_email'], $formData['activation_code']);
        }
    }
}
