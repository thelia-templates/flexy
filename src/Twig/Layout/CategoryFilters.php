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

namespace FlexyBundle\Twig\Layout;

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
use TwigEngine\Service\DataAccess\DataAccessService;

#[AsLiveComponent(template: '@components/Layout/CategoryFilters/CategoryFilters.html.twig')]
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

    public const ITEMS_PER_PAGE = 1;

    #[LiveProp]
    public ?int $categoryId = null;

    #[LiveProp]
    public ?int $page = 1;

    #[LiveProp(writable: true, url: true)]
    public ?array $tfilters = [];

    #[ExposeInTemplate]
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
    ) {
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
        ], 'jsonld');

        $this->getPagination($request);
        $this->products = $request['hydra:member'];
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
    public function save(#[LiveArg] $reset = false): void
    {
        $this->submitForm();

        if ($reset) {
            $this->resetForm();
            $this->tfilters = [];
        }

        if ($this->tfilters = $this->getForm()->getData()) {
            $this->tfilters = $this->getForm()->getData();
        }

        $request = $this->dataAccessService->resources('/api/front/products', [
            'productCategories.category.id' => $this->categoryId,
            'tfilters' => $this->tfilters,
            'itemsPerPage' => self::ITEMS_PER_PAGE,
            'page' => $this->page,
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
        preg_match('/\d+/', $request['hydra:view']['@id'], $matches);

        $baseUrl = $this->getProductUrl($this->requestStack->getCurrentRequest()->headers->get('referer') ?? '', $this->tfilters);

        $this->pagination = [
            'totalItems' => $request['hydra:totalItems'],
            'itemsPerPage' => self::ITEMS_PER_PAGE,
            'currentPage' => $matches[0] ?? 1,
            'baseUrl' => $baseUrl,
        ];
    }

    public function getProductUrl(string $base, $params = []): string
    {
        return $base.(\count($params) ? '?' : '').http_build_query($params);
    }
}
