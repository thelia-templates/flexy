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

use FlexyBundle\Twig\Organisms\RegisterValidationCode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints;
use Thelia\Core\Translation\Translator;

class CustomerActivationForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('customer_email', EmailType::class,
                [
                    'constraints' => [
                        new Constraints\NotBlank([
                            'message' => Translator::getInstance()->trans('Email is required'),
                        ]),
                        new Constraints\Email([
                            'message' => Translator::getInstance()->trans('Please enter a valid email address'),
                        ]),
                    ],
                ]
            )
            ->add('activation_code', IntegerType::class, [
                'attr' => [
                    'maxlength' => RegisterValidationCode::CODE_CHARSETS_COUNT,
                    'pattern' => '[0-9]{'.RegisterValidationCode::CODE_CHARSETS_COUNT.'}',
                    'placeholder' => '000000',
                ],
                'constraints' => [
                    new Constraints\NotBlank([
                        'message' => Translator::getInstance()->trans('Activation code is required'),
                    ]),
                    new Constraints\Length([
                        'min' => RegisterValidationCode::CODE_CHARSETS_COUNT,
                        'max' => RegisterValidationCode::CODE_CHARSETS_COUNT,
                        'exactMessage' => Translator::getInstance()->trans('Activation code must be exactly {{ limit }} digits'),
                        'minMessage' => Translator::getInstance()->trans('Activation code is too short ({{ limit }} digits required)'),
                        'maxMessage' => Translator::getInstance()->trans('Activation code is too long ({{ limit }} digits maximum)'),
                    ]),
                    new Constraints\Regex([
                        'pattern' => '/^[0-9]{'.RegisterValidationCode::CODE_CHARSETS_COUNT.'}$/',
                        'message' => Translator::getInstance()->trans('Activation code must contain only '.RegisterValidationCode::CODE_CHARSETS_COUNT.' digits'),
                    ]),
                ],
            ]);
    }
}
