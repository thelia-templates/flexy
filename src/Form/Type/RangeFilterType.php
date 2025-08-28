<?php

declare(strict_types=1);

namespace FlexyBundle\Form\Type;

use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;

class RangeFilterType extends FormType
{
    public static function getExtendedTypes(): iterable
    {
        return [RangeType::class];
    }

    public function getBlockPrefix(): string
    {
        return 'range_filter';
    }
}
