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

namespace FlexyBundle\Form;

use Symfony\Component\Form\Extension\Core\Type\BirthdayType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;
use Thelia\Model\Base\CustomerQuery;

class CustomerRegisterForm extends BaseForm
{
    protected function buildForm(): void
    {
        $this->formBuilder
          // Champs textuels basiques
          ->add('text', TextType::class, [
              'label' => 'Texte simple',
              'attr' => ['placeholder' => 'Entrez du texte'],
              'help' => 'test help message',
          ])
          ->add('email', EmailType::class, [
              'label' => 'Adresse email',
            'required' =>true,
          ])
          ->add('tel', TelType::class, [
              'label' => 'Numéro de téléphone',
            'required' =>true,
          ])
          ->add('password', PasswordType::class, [
              'label' => 'Mot de passe',
            'required' =>true,
          ])
          ->add('textarea', TextareaType::class, [
              'label' => 'Texte long',
              'required' =>true,
              'attr' => [
                  'rows' => 5,
              ],
          ])

          // Champs numériques
          ->add('integer', IntegerType::class, [
              'label' => 'Nombre entier',
            'required' =>true,
          ])
          ->add('number', NumberType::class, [
              'label' => 'Nombre décimal',
            'required' =>true,
          ])
          ->add('money', MoneyType::class, [
              'label' => 'Montant',
              'required' =>true,
              'currency' => 'EUR',
          ])
          ->add('percent', PercentType::class, [
              'label' => 'Pourcentage',
              'required' =>true,
              'scale' => 2,
          ])

          // Champs de date et heure
          ->add('date', DateType::class, [
              'label' => 'Date',
              'required' =>true,
              'widget' => 'single_text',
          ])
          ->add('datetime', DateTimeType::class, [
              'label' => 'Date et heure',
              'required' =>true,
              'widget' => 'single_text',
          ])
          ->add('time', TimeType::class, [
              'label' => 'Heure',
              'required' =>true,
              'widget' => 'single_text',
          ])
          ->add('birthday', BirthdayType::class, [
              'label' => 'Date de naissance',
              'required' =>true,
              'widget' => 'single_text',
          ])

          // Champs de choix
          ->add('checkbox', CheckboxType::class, [
              'label' => 'Case à cocher',
              'required' =>true,
              'required' => false,
          ])
          ->add('choice', ChoiceType::class, [
              'label' => 'Liste déroulante',
              'required' =>true,
              'attr' => [
                  'placeholder' => 'Choisissez une option',
              ],
              'choices' => [
                  'Option 1' => 'option1',
                  'Option 2' => 'option2',
                  'Option 3' => 'option3',
              ],
              'data' => 'option3',
          ])
          ->add('multiple_choice', ChoiceType::class, [
              'label' => 'Choix multiples checkbox',
              'required' =>true,
              'multiple' => true,
              'expanded' => true,
              'choices' => [
                  'Choix 1' => 'choice1',
                  'Choix 2' => 'choice2',
                  'Choix 3' => 'choice3',
              ],
          ])
          ->add('multiple_choice_radio', ChoiceType::class, [
              'label' => 'Choix multiples radio',
              'required' =>true,
              'multiple' => false,
              'expanded' => true,
              'choices' => [
                  'Choix 1' => 'choice1',
                  'Choix 2' => 'choice2',
                  'Choix 3' => 'choice3',
              ],
          ])

          // Champs spéciaux
          ->add('file', FileType::class, [
              'label' => 'Fichier',
              'required' =>true,
              'required' => false,
          ])

          ->add('range', RangeType::class, [
              'label' => 'Curseur',
              'required' =>true,
              'attr' => [
                  'min' => 0,
                  'max' => 100,
              ],
          ])
          ->add('url', UrlType::class, [
              'label' => 'URL',
            'required' =>true,
          ])
          ->add('search', SearchType::class, [
              'label' => 'Recherche',
            'required' =>true,
          ])
          // Bouton de soumission
          ->add('submit', SubmitType::class, [
              'label' => 'Envoyer',
          ]);
    }

    protected function getLocale()
    {
        $session = $this->request?->getSession();

        return $session?->getLang()->getLocale();
    }

    public function verifyExistingEmail($value, ExecutionContextInterface $context): void
    {
        $customer = CustomerQuery::create()->findOneByEmail($value);
        if ($customer) {
            $context->addViolation(Translator::getInstance()->trans('This email is already used'));
        }
    }

    public function verifyEmailField($value, ExecutionContextInterface $context): void
    {
        $data = $context->getRoot()->getData();

        if (isset($data['email_confirm']) && $data['email'] != $data['email_confirm']) {
            $context->addViolation(
                Translator::getInstance()->trans('email confirmation is not the same as email field')
            );
        }
    }

    public function verifyPasswordField($value, ExecutionContextInterface $context): void
    {
        $data = $context->getRoot()->getData();

        if ($data['password'] != $data['password_confirm']) {
            $context->addViolation(Translator::getInstance()->trans('password confirmation is not the same as password field'));
        }
    }
}
