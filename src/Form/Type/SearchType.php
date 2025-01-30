<?php

namespace FlexyBundle\Form\Type;

use Symfony\Component\Form\Extension\Core\Type\TextType;

class SearchType extends TextType
{
  public static function getExtendedTypes(): iterable
  {
    return [TextType::class];
  }

  public function getBlockPrefix(): string
  {
    return 'search';
  }
}
