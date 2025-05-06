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
use FlexyBundle\Form\Type\FilterChoiceType;
use FlexyBundle\Form\Type\SelectChoiceType;
use FlexyBundle\Form\Type\SortChoiceType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use TwigEngine\Service\DataAccess\DataAccessService;

#[AsLiveComponent(template: '@components/Layout/CategoryProducts/CategoryProducts.html.twig')]
class CategoryProducts extends AbstractController
{
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

    public const ITEMS_PER_PAGE = 12;

    #[LiveProp]
    public ?int $categoryId = null;

    #[LiveProp]
    public ?int $page = 1;

    #[LiveProp(writable: false, url: true)]
    public ?array $tfilters = [];

    #[ExposeInTemplate]
    public ?array $products = [];

    #[ExposeInTemplate]
    public ?array $filters = [];

    #[ExposeInTemplate]
    public ?array $sorts = [];

    #[ExposeInTemplate]
    public ?array $sourceData = [];

    public function __construct(private DataAccessService $dataAccessService, private RequestStack $requestStack)
    {
    }

    public function mount(?int $initialCategoryId, ?int $initialPage, ?array $sourceData): void
    {
        $this->categoryId = $initialCategoryId;
        $this->page = $initialPage;
        $this->sourceData = $sourceData;
        $tfilters = $this->requestStack->getCurrentRequest()->get('tfilters');

        if (\is_array($tfilters) && \count($tfilters) > 0) {
            $this->tfilters = $tfilters;
        }

        $request = $this->dataAccessService->resources('/api/front/products', [
            'productCategories.category.id' => $initialCategoryId,
            'tfilters' => $this->tfilters,
            'itemsPerPage' => self::ITEMS_PER_PAGE,
            'page' => $initialPage,
            'order[productCategories.position]' => 'asc',
        ], 'jsonld');
        $this->products = $request['hydra:member'];
    }

    protected function instantiateForm(): FormInterface
    {
        $formBuilder = $this->createFormBuilder(null, ['attr' => ['class' => 'relative flex flex-col gap-[30px]']]);

        if (empty($this->getSorts())) {
            $values = [];

            foreach ($this->getSorts() as $sort) {
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
            )->add('sort', SortChoiceType::class, [
                'label' => 'Choose',
                'choices' => $values,
                'required' => false,
            ]));
        }

        if (!empty($this->getFilters())) {
            $formBuilder->add($formBuilder->create(
                'tfilters',
                FieldsetType::class,
                [
                    'by_reference' => true,
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

            foreach ($this->getFilters() as $filter) {
                $values = [];

                foreach ($filter['values'] as $value) {
                    $values[$value['title']] = $value['id'];
                }

                $fieldName = 'tfilters_'.$filter['type'];

                if ($filter['id']) {
                    $fieldName .= '_'.$filter['id'];
                }
                $formBuilder->get('tfilters')->add(
                    $fieldName,
                    $filter['inputType'] === 'select' ? SelectChoiceType::class : FilterChoiceType::class,
                    [
                        'label' => $filter['title'],
                        'choices' => $values,
                        'data' => $this->tfilters[$filter['type']] ?? null,
                        'multiple' => true,
                        'required' => false,
                    ]
                );
            }
        }

        return $formBuilder->getForm();
    }

    #[LiveAction]
    public function save(#[LiveArg] ?string $order = 'asc', #[LiveArg] ?bool $reset = false): void
    {
        $this->submitForm();

        if ($reset) {
            $this->tfilters = [];
            $this->resetForm();
        }

        if ($this->getForm()->getData()) {
            $this->tfilters = $this->normalizeFormDataToFilters($this->getForm()->getData());
        }

        $filters = $this->tfilters;
        $filters['category'] = $this->categoryId;

        $request = $this->dataAccessService->resources('/api/front/products', [
            'tfilters' => $filters,
            'itemsPerPage' => self::ITEMS_PER_PAGE,
            'page' => $this->page,
            'category_depth' => 3,
        ], 'jsonld');
        $this->products = $request['hydra:member'];
    }

    public function getFilters(): array
    {
        $this->filters = $this->dataAccessService->resources('/api/front/tfilters/products', [
            'tfilters[categories]' => $this->categoryId,
        ]);

        return $this->filters;
    }

    public function getSorts(): array
    {
        $this->sorts = self::SORTS;

        return $this->sorts;
    }

    public function normalizeFormDataToFilters(array $formData): array
    {
        $filters = [];

        $provided_data = array_filter($formData, function ($filter) {
            return \is_array($filter) && \count($filter) > 0;
        });

        foreach ($provided_data as $name => $values) {
            $pathFilter = explode('_', $name);

            if (\count($pathFilter) > 1 && $pathFilter[0] === 'tfilters') {
                foreach ($values as $value) {
                    $filters[$pathFilter[1]][] = $value;
                }
            } else {
                $filters[$name] = $values;
            }
        }

        return $filters;
    }
}
