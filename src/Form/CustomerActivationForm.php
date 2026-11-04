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

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Model\Customer;

class CustomerActivationForm extends AbstractType
{
    public function __construct(
        #[Autowire(service: 'translator')]
        private readonly TranslatorInterface $translation,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // The account being activated is the pending registration held in the session, so
        // this form carries no email field: a submitted one would let a caller aim the code
        // check at any address, and answer whether that address has an account.
        $builder
            ->add('activation_code', TextType::class, [
                'label' => $this->translation->trans('Enter the code received by e-mail'),
                'attr' => [
                    'maxlength' => Customer::CODE_LENGTH,
                    'pattern' => '[0-9]{'.Customer::CODE_LENGTH.'}',
                    'placeholder' => '000000',
                ],
                'constraints' => [
                    new Constraints\NotBlank(),
                    new Constraints\Length([
                        'min' => Customer::CODE_LENGTH,
                        'max' => Customer::CODE_LENGTH,
                    ]),
                    new Constraints\Regex([
                        'pattern' => '/^[0-9]{'.Customer::CODE_LENGTH.'}$/',
                        'message' => $this->translation->trans('Activation code must contain only digits'),
                    ]),
                ],
            ]);
    }
}
