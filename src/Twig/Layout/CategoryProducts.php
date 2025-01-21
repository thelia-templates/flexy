<?php

namespace FlexyBundle\Twig\Layout;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\Component\Form\FormInterface;
use TwigEngine\Service\DataAccess\DataAccessService;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use FlexyBundle\Form\Type\FilterChoiceType;
use FlexyBundle\Form\Type\SelectChoiceType;
use FlexyBundle\Form\Type\FieldsetType;
use FlexyBundle\Form\Type\SortChoiceType;

#[AsLiveComponent(template: '@components/Layout/CategoryProducts/CategoryProducts.html.twig')]
class CategoryProducts extends AbstractController
{
  use DefaultActionTrait;
  use ComponentWithFormTrait;

  public const SORTS = [
    [
      'id' => 4,
      'title' => 'Ascending price',
      'value' => 'asc'
    ],
    [
      'id' => 5,
      'title' => 'Descending price',
      'value' => 'desc'
    ]
  ];

  #[LiveProp]
  public int $categoryId;

  #[LiveProp]
  public ?int $page = 1;

  #[ExposeInTemplate]
  public ?array $products = [];

  #[ExposeInTemplate]
  public ?array $filters = [];

  #[ExposeInTemplate]
  public ?array $sorts = [];

  public function __construct(private DataAccessService $dataAccessService) {}

  protected function instantiateForm(): FormInterface
  {
    $formBuilder = $this->createFormBuilder(null, ['attr' => ['class' => 'relative flex flex-col gap-[30px]']]);

    if (!empty($this->getSorts())) {

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
            'class' => 'Category-filter lg:hidden'
          ],
          'label_attr' => [
            'class' => 'block mb-6 h4'
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
            'class' => 'block mb-6 h4'
          ],
          'attr' => [
            'class' => 'Category-filter'
          ],
        ]
      ));

      foreach ($this->getFilters() as $filter) {
        $values = [];

        foreach ($filter['values'] as $value) {
          $values[$value['title']] = $value['id'];
        }

        $fieldName = $filter['type'];

        if ($filter['id']) {
          $fieldName .= '_' . $filter['id'];
        }
        $formBuilder->get('tfilters')->add(
          $fieldName,
          $filter['inputType'] === 'select' ? SelectChoiceType::class : FilterChoiceType::class,
          [
            'label' => $filter['title'],
            'choices' => $values,
            'multiple' => true,
            'required' => false,
          ]
        );
      }
    }

    return $formBuilder->getForm();
  }

  #[LiveAction]
  public function save(#[LiveArg] ?string $order = 'asc', #[LiveArg] ?bool $reset = false)
  {
    $this->submitForm();

    if ($reset) {
      $this->resetForm();
    }

    $tfilters = $this->normalizeFormDataToFilters($this->getForm()->getData() ?? []);

    $this->products = $this->dataAccessService->resources('/api/front/products', [
      'tfilters' => $tfilters,
      'itemsPerPage' => 9,
      'page' => $this->page,
      'order' => [
        'ref' => $order
      ]
    ]);

    return $this->products;
  }

  public function getFilters(): array
  {
    $this->filters =  $this->dataAccessService->resources('/api/front/tfilters/products', [
      'tfilters[categories]' => 1
    ]);

    return $this->filters;
  }

  public function getSorts(): array
  {
    $this->sorts =  [];
    return $this->sorts;
  }

  public function getProducts(): array
  {
    if (empty($this->products)) {
      $this->products = $this->dataAccessService->resources('/api/front/products', [
        'productCategories.category.id' => $this->categoryId,
        'itemsPerPage' => 9,
        'page' => $this->page,
        'order' => [
          'ref' => 'asc'
        ]
      ]);
    }
    return $this->products;
  }


  public function normalizeFormDataToFilters(array $formData): array
  {
    $filters = [];

    $provided_data = array_filter($formData, function ($filter) {
      return count($filter) > 0;
    });

    foreach ($provided_data as $name => $value) {
      $pathFilter = explode('_', $name);

      if (count($pathFilter) > 1) {
        $filters[$pathFilter[0]][$pathFilter[1]] = $value;
      } else {
        $filters[$name] = $value;
      }
    }

    return $filters;
  }
}
