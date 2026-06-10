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

namespace FlexyBundle\UiComponents\CategoryFilters;

use FlexyBundle\DTO\ProductDTO;
use FlexyBundle\Form\Type\FieldsetType;
use FlexyBundle\Service\FormService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
use Thelia\Api\Service\DataAccess\DataAccessService;

#[AsLiveComponent(name: 'Flexy:CategoryFilters', template: '@UiComponents/CategoryFilters/CategoryFilters.html.twig')]
class CategoryFilters extends AbstractController
{
    use ComponentToolsTrait;
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    public const SORTS = [
        [
            'id' => 4,
            'title' => 'Ascending price',
            'value' => 'asc',
        ],
        [
            'id' => 5,
            'title' => 'Descending price',
            'value' => 'desc',
        ],
    ];

    public const ITEMS_PER_PAGE = 30;

    #[LiveProp]
    public ?int $categoryId = null;

    #[LiveProp]
    public ?int $page = 1;

    #[LiveProp(writable: true, url: true)]
    public ?array $tfilters = [];

    public ?array $products = [];

    #[LiveProp]
    public ?array $pagination = [];

    #[ExposeInTemplate]
    public ?array $filters = null;

    #[ExposeInTemplate]
    public ?array $sorts = [];

    #[ExposeInTemplate]
    public ?array $sourceData = [];

    public function __construct(
        private readonly DataAccessService $dataAccessService,
        private readonly RequestStack $requestStack,
        private readonly FormService $formService,
    ) {}

    public function mount(?int $initialCategoryId, ?int $initialPage, ?array $sourceData, ?bool $promo = false, ?bool $newness = false): void
    {
        $this->categoryId = $initialCategoryId;
        $this->page = $initialPage;
        $this->sourceData = $sourceData;

        $tfilters = $this->requestStack->getCurrentRequest()->get('tfilters');

        if (\is_array($tfilters) && \count($tfilters) > 0) {
            $this->tfilters = $tfilters;
        }

        $params = [
            'productCategories.category.id' => $initialCategoryId,
            'tfilters' => $this->tfilters,
            'itemsPerPage' => self::ITEMS_PER_PAGE,
            'page' => $initialPage,
            'visible' => true,
            'order[productCategories.position]' => 'asc',
        ];

        if ($promo) {
            $params['productSaleElements.promo'] = true;
        }

        if ($newness) {
            $params['productSaleElements.newness'] = true;
        }

        $request = $this->dataAccessService->resources('/api/front/products', $params, 'jsonld');

        $this->getPagination($request);
        $this->products = ProductDTO::fromCollection($request['hydra:member']);
    }

    protected function instantiateForm(): FormInterface
    {
        $formBuilder = $this->createFormBuilder(null, ['attr' => ['class' => 'relative flex flex-col gap-[30px]']]);

        $sorts = $this->getSorts();

        if ($sorts) {
            $values = [];
            foreach ($sorts as $sort) {
                $values[$sort['title']] = $sort['value'];
            }

            $formBuilder->add($formBuilder->create(
                'sorts',
                FieldsetType::class,
                [
                    'by_reference' => true,
                    'label' => 'Sort By',
                    'inherit_data' => true,
                    'attr' => [
                        'class' => 'Category-filter lg:hidden',
                    ],
                    'label_attr' => [
                        'class' => 'block mb-6 h4',
                    ],
                ]
            )->add('sort', ChoiceType::class, [
                'label' => 'Choose',
                'choices' => $values,
                'required' => false,
            ]));
        }

        $fitlers = $this->getFilters();
        if ($fitlers) {
            $formBuilder->add($formBuilder->create(
                'tfilters',
                FieldsetType::class,
                [
                    'label' => 'Filter By',
                    'inherit_data' => true,
                    'label_attr' => [
                        'class' => 'block mb-6 h4',
                    ],
                    'attr' => [
                        'class' => 'Category-filter',
                    ],
                ]
            ));
            foreach ($fitlers as $filter) {
                $this->formService->renderFieldFromFieldType($filter, $formBuilder->get('tfilters'), $this->tfilters ?? []);
            }
        }

        return $formBuilder->getForm();
    }

    #[LiveAction]
    public function save(#[LiveArg] $reset = false, #[LiveArg] $order = 'asc'): void
    {
        $this->submitForm();

        if ($reset) {
            $this->tfilters = [];
            $this->resetForm();
        } else {
            $this->tfilters = $this->getForm()->getData() ?? [];
        }

        $request = $this->dataAccessService->resources('/api/front/products', [
            'productCategories.category.id' => $this->categoryId,
            'tfilters' => $this->tfilters,
            'itemsPerPage' => self::ITEMS_PER_PAGE,
            'page' => $this->page,
            'untaxed_price_order' => $order,
        ], 'jsonld');

        $this->getPagination($request);
        $this->products = $request['hydra:member'];
    }

    public function getFilters(): array
    {
        $this->filters = $this->dataAccessService->resources('/api/front/tfilters/products', [
            'tfilters[category]' => $this->categoryId,
        ]);

        return $this->filters;
    }

    public function getSorts(): array
    {
        $this->sorts = self::SORTS;

        return $this->sorts;
    }

    public function getPagination(array $request): void
    {
        $totalItems = $request['hydra:totalItems'] ?? 0;

        $this->pagination = [
            'totalItems' => $totalItems,
            'itemsPerPage' => self::ITEMS_PER_PAGE,
            'currentPage' => $this->page,
            'baseUrl' => $this->getProductUrl(),
        ];
    }

    public function getProductUrl(string $base = "", $params = []): string
    {
        return $base . (\count($params) ? '?' : '') . http_build_query($params);
    }
}
