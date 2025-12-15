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

namespace FlexyBundle\UiComponents\CustomerInformationForm;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use TwigEngine\Service\FormService;

#[AsLiveComponent(name: 'Flexy:CustomerInformationForm', template: '@UiComponents/CustomerInformationForm/CustomerInformationForm.html.twig')]
class CustomerInformationForm extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    public array $formData = [];

    public function __construct(
        private readonly FormService $formService,
        private readonly FormFactoryInterface $formFactory,
        private readonly RequestStack $requestStack,
        #[Autowire(service: 'translator')]
        public ?TranslatorInterface $translator,
    ) {
    }

    protected function instantiateForm(): FormInterface
    {
        return $this->formService->getFormByName('flexybundle_form_customer_informations_form', $this->formData);
    }
}
