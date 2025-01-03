<?php

namespace FlexyBundle\Form\Type;

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

class PillType extends ChoiceType
{
  public static function getExtendedTypes(): iterable
  {
    return [ChoiceType::class];
  }

  public function getBlockPrefix(): string
  {
    return 'pill';
  }
}
