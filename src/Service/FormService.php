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

namespace FlexyBundle\Service;

use FlexyBundle\Event\FilterField\FilterCheckboxRendered;
use FlexyBundle\Event\FilterField\FilterRangeRendered;
use FlexyBundle\Event\FilterField\FilterTextRendered;
use FlexyBundle\Form\Type\FieldsetType;
use FlexyBundle\Form\Type\PillType;
use FlexyBundle\Form\Type\RangeFilterType;
use FlexyBundle\Form\Type\RangeGroupType;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Turns a product filter coming from /api/front/tfilters/* into a form field.
 * The five field types handled here are the ones Thelia core can emit.
 *
 * @phpstan-type FilterValue array{id?: int|string, title?: int|string, count?: int|null}
 * @phpstan-type Filter array{type: string, title: string, fieldType?: string, id?: int|string, values?: list<FilterValue>}
 */
final readonly class FormService
{
    public const RANGE_SUB_INPUTS = ['min', 'max'];

    public function __construct(
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * @param Filter $filter
     */
    public function renderFieldFromFieldType(array $filter, FormBuilderInterface $fieldset, array $tfilters): void
    {
        if (!$fieldset->has($filter['type'])) {
            $fieldset->add($fieldset->create(
                $filter['type'],
                FieldsetType::class,
                [
                    'by_reference' => true,
                    'inherit_data' => false,
                ]
            ));
        }

        match ($filter['fieldType'] ?? 'checkbox') {
            'checkbox', 'radio' => $this->renderCheckbox($filter, $fieldset->get($filter['type']), $tfilters),
            'input' => $this->renderInput($filter, $fieldset),
            'range' => $this->renderRange($filter, $fieldset->get($filter['type']), $tfilters),
            'delta' => $this->renderDelta($filter, $fieldset->get($filter['type']), $tfilters),
            // A field type the core (or a third-party module) may add later must not take down the
            // whole category page: leave the empty type fieldset in place and skip the unknown field.
            default => null,
        };
    }

    /**
     * `tfilters` is a url-bound, writable LiveProp, so a forged URL can put a scalar where a nested
     * array is expected. Read the per-type slice defensively: a malformed value degrades to
     * "nothing selected" instead of a TypeError on string offset access.
     */
    private static function currentValues(array $tfilters, string $type): array
    {
        return \is_array($tfilters[$type] ?? null) ? $tfilters[$type] : [];
    }

    /**
     * Field name for a filter, doubling as its key inside tfilters[type][...].
     * Some filters carry no id of their own (brand): the API only uses this level as a grouping
     * key, so the filter type is a fine stand-in — but it must never be empty, as an unnamed
     * form field cannot be attached to a parent.
     */
    private static function fieldName(array $filter): string
    {
        return (string) ($filter['id'] ?? $filter['type']);
    }

    private function renderCheckbox(array $filter, FormBuilderInterface $fieldset, array $tfilters): void
    {
        $values = [];
        $counts = [];

        foreach ($filter['values'] as $value) {
            $values[$value['title'] ?? ''] = $value['id'];

            if (isset($value['count'])) {
                $counts[(string) $value['id']] = (int) $value['count'];
            }
        }

        $fieldName = self::fieldName($filter);

        $formEvent = new FilterCheckboxRendered(
            $fieldName,
            PillType::class,
            [
                'label' => $filter['title'],
                'choices' => $values,
                // The widget reads the count back from the choice attributes.
                'choice_attr' => static fn (mixed $choice): array => isset($counts[(string) $choice]) ? ['data-count' => $counts[(string) $choice]] : [],
                'data' => self::currentValues($tfilters, $filter['type'])[$fieldName] ?? [],
                'multiple' => true,
                'required' => false,
            ],
            $filter
        );
        $this->dispatcher->dispatch($formEvent);

        $fieldset->add(
            $formEvent->getName(),
            $formEvent->getType(),
            $formEvent->getOptions()
        );
    }

    private function renderInput(array $filter, FormBuilderInterface $fieldset): void
    {
        $formEvent = new FilterTextRendered(
            'sf',
            TextType::class,
            ['label' => $filter['title']],
            $filter
        );
        $this->dispatcher->dispatch($formEvent);

        $fieldset->add(
            $formEvent->getName(),
            $formEvent->getType(),
            $formEvent->getOptions()
        );
    }

    private function renderDelta(array $filter, FormBuilderInterface $fieldset, array $tfilters): void
    {
        // min()/max() throw a ValueError on an empty array: a range with no values is meaningless,
        // so skip it rather than 500 the whole page.
        if ($filter['values'] === []) {
            return;
        }

        $groupName = self::fieldName($filter);

        $fieldset->add($fieldset->create(
            $groupName,
            RangeGroupType::class,
            [
                'label' => $filter['title'],
                'mapped' => true,
            ]
        ));

        $min = min(array_column($filter['values'], 'title'));
        $max = max(array_column($filter['values'], 'title'));

        $currentGroup = self::currentValues($tfilters, $filter['type'])[$groupName] ?? [];
        $currentGroup = \is_array($currentGroup) ? $currentGroup : [];
        $currentMin = $currentGroup['min'] ?? $min;
        $currentMax = $currentGroup['max'] ?? $max;

        foreach (self::RANGE_SUB_INPUTS as $range) {
            $fieldset->get($groupName)->add(
                $range,
                RangeType::class,
                [
                    // The min/max bounds and current value feed Fields/RangeSlider (via the
                    // range_group_row form theme block); that component owns the Stimulus wiring.
                    'attr' => [
                        'min' => $min,
                        'max' => $max,
                    ],
                    'data' => $range === 'min' ? $currentMin : $currentMax,
                    'label' => $range,
                ]
            );
        }
    }

    private function renderRange(array $filter, FormBuilderInterface $fieldset, array $tfilters): void
    {
        // min()/max() throw a ValueError on an empty array: a range with no values is meaningless,
        // so skip it rather than 500 the whole page.
        if ($filter['values'] === []) {
            return;
        }

        $fieldName = self::fieldName($filter);

        $formEvent = new FilterRangeRendered(
            $fieldName,
            RangeFilterType::class,
            [
                'label' => $filter['title'],
                'attr' => [
                    'min' => min(array_column($filter['values'], 'title')),
                    'max' => max(array_column($filter['values'], 'title')),
                    'step' => 10,
                ],
                'data' => self::currentValues($tfilters, $filter['type'])[$fieldName] ?? null,
            ],
            $filter
        );
        $this->dispatcher->dispatch($formEvent);

        $fieldset->add(
            $formEvent->getName(),
            $formEvent->getType(),
            $formEvent->getOptions()
        );
    }
}
