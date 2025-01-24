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
use Thelia\Core\HttpFoundation\Request;

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

  const ITEMS_PER_PAGE = 30;

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

  public function __construct(private DataAccessService $dataAccessService, private  Request $request) {}


  public function mount(?int $initialCategoryId, ?int $initialPage): void
  {
    $this->categoryId = $initialCategoryId;
    $this->page = $initialPage;
    $tfilters = $this->request->get('tfilters');

    if (is_array($tfilters) && count($tfilters) > 0) {
      $this->tfilters = $tfilters;
    }

    $this->products = $this->dataAccessService->resources('/api/front/products', [
      'productCategories.category.id' => $initialCategoryId,
      'tfilters' =>  $this->tfilters,
      'itemsPerPage' => self::ITEMS_PER_PAGE,
      'page' => $initialPage,
      'order' => [
        'ref' => 'asc'
      ]
    ]);
  }

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

        $fieldName = 'tfilters_' . $filter['type'];

        if ($filter['id']) {
          $fieldName .= '_' . $filter['id'];
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
  public function save(#[LiveArg] ?string $order = 'asc', #[LiveArg] ?bool $reset = false)
  {
    $this->submitForm();

    if ($reset) {
      $this->tfilters = [];
      $this->resetForm();
    }

    if ($this->getForm()->getData()) {
      $this->tfilters = $this->normalizeFormDataToFilters($this->getForm()->getData());
    }

    $this->products = $this->dataAccessService->resources('/api/front/products', [
      'productCategories.category.id' => $this->categoryId,
      'tfilters' =>  $this->tfilters  ?? [],
      'itemsPerPage' => self::ITEMS_PER_PAGE,
      'page' => $this->page,
      'order' => [
        'ref' => $order
      ]
    ]);
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
    $this->sorts = self::SORTS;
    return $this->sorts;
  }

  public function normalizeFormDataToFilters(array $formData): array
  {
    $filters = [];

    $provided_data = array_filter($formData, function ($filter) {
      return is_array($filter) && count($filter) > 0;
    });

    foreach ($provided_data as $name => $values) {
      $pathFilter = explode('_', $name);

      if (count($pathFilter) > 1 && $pathFilter[0] === 'tfilters') {
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
