<?php

namespace FlexyBundle\Twig\Layout;

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use TwigEngine\Service\DataAccess\DataAccessService;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Form\Definition\FrontForm;
use TwigEngine\Service\FormService;

#[AsLiveComponent(template: '@components/Layout/UpdatePassword/UpdatePassword.html.twig')]
class UpdatePassword extends BaseFrontController
{
  use ComponentToolsTrait;
  use ComponentWithFormTrait;
  use DefaultActionTrait;

  #[LiveProp]
  public ?array $initialFormData = null;

  public function __construct(
    private DataAccessService $dataAccessService,
    private FormService $formService,
    private FormFactoryInterface $formFactory,
  ) {}

  protected function instantiateForm(): FormInterface
  {
    return $this->formService->getFormByName(FrontForm::CUSTOMER_PASSWORD_UPDATE);
  }

  #[LiveAction]
  public function save(): void
  {
    $this->submitForm();
    if (!$this->getForm()->isValid()) {
      return;
    }
  }
}
