<?php

namespace FlexyBundle\Form\Type;

use Symfony\Component\Form\Extension\Core\Type\FormType;

class RangeGroupType extends FormType
{
  public static function getExtendedTypes(): iterable
  {
    return [FormType::class];
  }

  public function getBlockPrefix(): string
  {
    return 'rangeGroup';
  }
}
