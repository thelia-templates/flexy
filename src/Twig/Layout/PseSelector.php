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

namespace FlexyBundle\Twig\Layout;

use FlexyBundle\Form\Type\FieldsetType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Form\Definition\FrontForm;
use Thelia\Service\Model\CartService;
use TwigEngine\Service\DataAccess\DataAccessService;
use TwigEngine\Service\DataAccess\ProductSaleElementsAccessService;
use TwigEngine\Service\FormService;

#[AsLiveComponent(template: '@components/Layout/PseSelector/PseSelector.html.twig')]
class PseSelector extends BaseFrontController
{
    use ComponentToolsTrait;
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public array $product;

    #[ExposeInTemplate]
    public ?array $pses = [];

    #[ExposeInTemplate]
    public ?array $currentPse = null;

    #[LiveProp]
    public ?array $initialFormData = null;

    public function __construct(
        private DataAccessService $dataAccessService,
        private ProductSaleElementsAccessService $pseAccessService,
        private FormService $formService,
        private FormFactoryInterface $formFactory,
        private CartService $cartService,
    ) {
    }

    protected function instantiateForm(): FormInterface
    {
        $productAttributes = $this->pseAccessService->attrAvByProduct($this->product['id']);

        $form = $this->formService->getFormByName(FrontForm::CART_ADD, [
            'product' => $this->product['id'],
            'product_sale_elements_id' => $this->getCurrentPse()['id'],
            'quantity' => 1,
            'append' => 1,
            'newness' => 0,
        ]);

        $form->add(
            'currentCombination',
            FieldsetType::class,
            [
                'by_reference' => true,
                'inherit_data' => true,
                'attr' => [
                    'class' => 'PseSelector',
                ],
            ]
        );

        foreach ($productAttributes as $attribute) {
            $choices = [];
            foreach ($attribute['values'] as $value) {
                $choices[$value['label']] = $value['id'];
            }
            $form->get('currentCombination')->add($attribute['id'], ChoiceType::class, [
                'label' => $attribute['label'],
                'choices' => $choices,
                'data' => reset($choices),
                'expanded' => true,
                'multiple' => false,
                'required' => false,
            ]);
        }

        return $form;
    }

    public function getPses(): array
    {
        if (0 !== \count($this->pses)) {
            return $this->pses;
        }

        $this->pses = json_decode($this->pseAccessService->psesByProduct($this->product['id']), true);

        return $this->pses;
    }

    #[LiveAction]
    public function getCurrentPse()
    {
        $pses = $this->getPses();

        if (0 === \count($pses)) {
            return [];
        }

        if (null === $this->currentPse) {
            $this->currentPse = array_filter($pses, function ($pse) {
                return $pse['isDefault'];
            })[0];
        } else {
            foreach ($pses as $pse) {
                if ($pse['combination'] == $this->formValues['currentCombination']) {
                    $this->currentPse = $pse;
                    break;
                }
            }
        }

        $this->formValues['product_sale_elements_id'] = $this->currentPse['id'];

        return $this->currentPse;
    }

    #[LiveAction]
    public function getQuantity(#[LiveArg] ?int $quantity = 1)
    {
        $this->formValues['quantity'] = $quantity;

        if ($quantity < 2) {
            $this->formValues['quantity'] = 1;
        }

        return $this->formValues['quantity'];
    }

    #[LiveAction]
    public function addToCart(): void
    {
        $this->submitForm();
        $this->cartService->addItem($this->getForm());
        $this->emit('addToCart', [
          'values' => $this->formValues,
        ]);
    }

    #[LiveAction]
    public function restockingAlert(): void
    {
    }
}
