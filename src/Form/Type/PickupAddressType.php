<?php

namespace FlexyBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Thelia\Core\Translation\Translator;

class PickupAddressType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options)
  {
    $builder
      ->add('address', SearchType::class, [
        'label'      => Translator::getInstance()->trans('Find a delivery address'),
        'label_attr' => [
          'for' => 'address',
        ],
        'help'       => Translator::getInstance()->trans("e.g. City, Postcode"),
      ]);
  }
}
