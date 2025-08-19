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

namespace FlexyBundle\Twig\Organisms;

use FlexyBundle\Form\CustomerActivationForm;
use FlexyBundle\Service\Customer\CustomerCodeProcessor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(template: '@components/Organisms/RegisterValidationCode/RegisterValidationCode.html.twig')]
class RegisterValidationCode extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;
    public const CODE_CHARSETS_COUNT = 6;

    #[LiveProp]
    public ?string $email = null;

    public ?int $nbChars = 0;

    public function __construct(protected CustomerCodeProcessor $customerCodeProcessor)
    {
    }

    public function mount(?string $email = null): void
    {
        $this->email = $email;
    }

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(CustomerActivationForm::class, [
            'customer_email' => $this->email,
        ]);
    }

    #[LiveAction]
    public function save(): void
    {
        $this->submitForm();

        $form = $this->getForm();
        try {
            $this->customerCodeProcessor->activateCustomerByCode(
                $form->get('customer_email')->getData(),
                (string) $form->get('activation_code')->getData()
            );
            $this->addFlash('success', 'Customer activated successfully.');
            dd('ok');
        } catch (\Exception $e) {

            dd($e);
            $this->addFlash('error', 'Activation failed: '.$e->getMessage());
        }
    }
}
