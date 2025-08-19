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

use FlexyBundle\Form\Type\FieldsetType;
use FlexyBundle\Form\Type\FilterChoiceType;
use FlexyBundle\Form\Type\RangeGroupType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class FormService
{
    public const RANGE_SUB_INPUTS = ['min', 'max'];

    public function renderFieldFromFieldType(array $filter, $fieldset): void
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
        match ($filter['fieldType']) {
            'checkbox', 'radio' => $this->renderCheckbox($filter, $fieldset->get($filter['type'])),
            'input' => $this->renderInput($filter, $fieldset),
            'range' => $this->renderRange($filter, $fieldset),
            'delta' => $this->renderDelta($filter, $fieldset->get($filter['type'])),
        };
    }

    private function renderCheckbox(array $filter, $fieldset): void
    {
        $values = [];

        foreach ($filter['values'] as $value) {
            $values[$value['title']] = $value['id'];
        }

        $fieldset->add(
            \sprintf('%d', $filter['id']),
            FilterChoiceType::class,
            [
                'label' => $filter['title'],
                'choices' => $values,
                //                'data' => $this->tfilters[$filter['type']][$value['id']] ?? null,
                'multiple' => true,
                'required' => false,
            ]
        );
    }

    private function renderInput(array $filter, $fieldset): void
    {
        $fieldset->add('sf', TextType::class, [
            'label' => $filter['title'],
        ]);
    }

    private function renderDelta(array $filter, $fieldset): void
    {
        $groupName = \sprintf('%d', $filter['id']);

        $fieldset->add($fieldset->create(
            $groupName,
            RangeGroupType::class,
            [
                'by_reference' => false,
                'label' => $filter['title'],
                'inherit_data' => false,
                'mapped' => true,
            ]
        ));

        $min = min(array_column($filter['values'], 'title'));
        $max = max(array_column($filter['values'], 'title'));

        foreach (self::RANGE_SUB_INPUTS as $range) {
            $fieldset->get($groupName)->add(
                $range,
                RangeType::class,
                [
                    'attr' => [
                        'min' => $min,
                        'max' => $max,
                        'step' => 10,
                        'data' => $range === 'min' ? $min : $max,
                        'data-range-target' => $range,
                        'data-action' => 'range#updateInput',
                    ],
                    'label' => $range,
                    'property_path' => '['.$range.']',
                ]
            );
        }
    }

    private function renderRange(array $filter, $fieldset): void
    {
    }

    public function clearData(array $data): array
    {
        $cleaned = [];

        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $cleanedSub = $this->clearData($value);

                if (!empty($cleanedSub)) {
                    $cleaned[$key] = $cleanedSub;
                }
            } else {
                if ($value) {
                    $cleaned[$key] = $value;
                }
            }
        }

        return $cleaned;
    }
}
