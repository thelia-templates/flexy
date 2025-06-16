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

namespace FlexyBundle\Twig\Page;

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use Thelia\Form\Definition\FrontForm;
use Thelia\Service\Model\CartService;
use TwigEngine\Service\DataAccess\DataAccessService;
use TwigEngine\Service\DataAccess\ProductSaleElementsAccessService;
use TwigEngine\Service\FormService;

#[AsLiveComponent(template: '@components/Page/ProductPage.html.twig')]
class Product
{
    use ComponentToolsTrait;
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public array $product;

    #[ExposeInTemplate]
    public ?array $pses = [];

    #[LiveProp]
    public ?array $psesImgs = [];

    #[LiveProp]
    public ?array $productImgs = [];

    #[LiveProp]
    public ?array $productAttrs = [];

    #[LiveProp]
    public ?array $currentCombination = [];

    #[LiveProp]
    public ?array $currentPse = null;

    #[LiveProp]
    public ?array $initialFormData = null;

    public function __construct(
        private DataAccessService $dataAccessService,
        private ProductSaleElementsAccessService $pseAccessService,
        private FormService $formService,
        private FormFactoryInterface $formFactory,
        private CartService $cartService,
        public RequestStack $requestStack,
    ) {
    }

    public function mount(array $product): void
    {
        $this->product = $product;
        $this->productAttrs = $this->pseAccessService->attrAvByProduct($this->product['id']);
        $this->setInitialCurrentPse();
        $this->setImages();
    }

    protected function instantiateForm(): FormInterface
    {
        return $this->formService->getFormByName(FrontForm::CART_ADD, [
            'product' => $this->product['id'],
            'product_sale_elements_id' => $this->currentPse['id'],
            'quantity' => 1,
            'append' => 1,
            'newness' => 0,
        ]);
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
    public function getQuantity(#[LiveArg] ?int $quantity = 1)
    {
        $this->formValues['quantity'] = $quantity;

        if ($quantity < 2) {
            $this->formValues['quantity'] = 1;
        }

        return $this->formValues['quantity'];
    }

    #[LiveAction]
    public function updateCurrentPseFromId(#[LiveArg] ?string $pseId = null): void
    {
        if (!$pseId) {
            return;
        }

        $pses = array_values(array_filter($this->getPses(), function ($pse) use (&$pseId) {
            return $pse['id'] == $pseId;
        }));

        $this->currentPse = $pses[0];
        $this->currentCombination = $this->currentPse['combination'];
        $this->formValues['product_sale_elements_id'] = $this->currentPse['id'];


    }

    #[LiveAction]
    public function updateCurrentCombination(#[LiveArg] $attr, #[LiveArg] $value): void
    {
        $newCombination = $this->currentCombination;
        $newCombination[$attr] = $value;

        $this->currentCombination = $newCombination;

        $this->updateCurrentPseFromCombination();
    }

    public function updateCurrentPseFromCombination(): void
    {
        $matchingCombinations = array_filter($this->getPses(), function ($pse) {
            return $pse['combination'] === $this->currentCombination;
        });
        $this->currentPse = reset($matchingCombinations);
        $this->formValues['product_sale_elements_id'] = $this->currentPse['id'];

        $this->dispatchBrowserEvent('change:pse', [
            'pseId' => $this->currentPse['id'],
        ]);
    }

    #[LiveAction]
    public function save(): void
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

    private function setInitialCurrentPse(): void
    {
        $pseRef = $this->requestStack->getCurrentRequest()?->query->get('ref');
        $pses = $this->getPses();

        $match = [];

        if ($pseRef) {
            $match = array_values(array_filter($pses, fn ($pse) => $pse['ref'] === $pseRef))[0] ?? null;
        }

        if (!$match) {
            $match = array_values(array_filter($pses, fn ($pse) => $pse['isDefault'] ?? false))[0] ?? null;
        }

        if ($match) {
            $this->currentPse = $match;
            $this->currentCombination = $match['combination'];
        }
    }

    private function setImages(): void
    {
        $pseIds = array_column($this->pses, 'id');

        if (!empty($pseIds)) {
            $this->psesImgs = $this->dataAccessService->resources(
                '/api/front/product_sale_elements_product_image',
                ['productSaleElements.product.id' => $this->product['id']]
            );
        }

        $this->productImgs = $this->dataAccessService->resources(
            '/api/front/product_images',
            [
              'not_in[id]' => array_column($this->psesImgs, 'productImageId'),
              'product.id' => $this->product['id']
            ]
        );

    }
}
