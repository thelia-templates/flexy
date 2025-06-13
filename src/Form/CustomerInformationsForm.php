<?php

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlexyBundle\Form;

use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints;
use Thelia\Core\Translation\Translator;

class CustomerInformationsForm extends CustomerRegisterForm
{
    protected function buildForm(): void
    {
        parent::buildForm();

        $this->formBuilder
          ->add('firstname', TextType::class, [
              'constraints' => [
                  new Constraints\NotBlank(),
              ],
              'label' => Translator::getInstance()->trans('Firstname'),
              'label_attr' => [
                  'for' => 'firstname',
              ],
          ])
          ->add('lastname', TextType::class, [
              'constraints' => [
                  new Constraints\NotBlank(),
              ],
              'label' => Translator::getInstance()->trans('Lastname'),
              'label_attr' => [
                  'for' => 'lastname',
              ],
          ])
          ->add(
              'accept_privacy_policy',
              CheckboxType::class,
              [
                  'label' => Translator::getInstance()->trans('By subscribing to our newsletter, you agree to our privacy policy.*'),
                  'label_attr' => [
                      'for' => 'accept_privacy_policy',
                  ],
                  'help' => Translator::getInstance()->trans('*Your data is processed by Thelia to manage our customer relations, to carry out statistical analyses, and to send you information about our products, services and events, if you have given your consent. You may object to these communications. You have the right to access, rectify, delete or object to the processing of your data. Contact our data manager at [dpo@domain.com].'),
              ]
          );
    }
}
