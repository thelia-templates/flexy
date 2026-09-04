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

use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PasswordStrength;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;

/**
 * The password that turns the account an order was placed under into a real one.
 *
 * Nothing else is asked: the name and the address are already on the order, and the
 * email is the one the confirmation was sent to. Choosing a password is the whole of
 * what was missing.
 *
 * Same strength rule as the registration form, so an account completed this way is not
 * weaker than one opened the usual way.
 */
class GuestAccountCreationForm extends BaseForm
{
    public const FORM_NAME = 'flexybundle_form_guest_account_creation';

    protected function buildForm(): void
    {
        $this->formBuilder->add('password', RepeatedType::class, [
            'type' => PasswordType::class,
            'invalid_message' => Translator::getInstance()->trans('The two passwords do not match.'),
            'first_options' => [
                'label' => Translator::getInstance()->trans('Password'),
                'label_attr' => ['for' => 'password'],
            ],
            'second_options' => [
                'label' => Translator::getInstance()->trans('Confirm password'),
                'label_attr' => ['for' => 'password_confirmation'],
            ],
            'constraints' => [
                new NotBlank(),
                new PasswordStrength(['minScore' => 1]),
            ],
        ]);
    }

    public static function getName(): string
    {
        return self::FORM_NAME;
    }
}
