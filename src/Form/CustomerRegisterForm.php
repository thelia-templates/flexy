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
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PasswordStrength;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;
use Thelia\Model\Base\CustomerQuery;

class CustomerRegisterForm extends BaseForm
{
    public function __construct(
        #[Autowire(service: 'translator')]
        protected readonly TranslatorInterface $translation,
    ) {
    }

    protected function buildForm(): void
    {
        $this->formBuilder->add('firstname', TextType::class, [
            'constraints' => [
                new NotBlank(),
            ],
            'label' => Translator::getInstance()->trans('Firstname'),
            'label_attr' => [
                'for' => 'firstname',
            ],
        ]);

        $this->formBuilder->add('lastname', TextType::class, [
            'constraints' => [
                new NotBlank(),
            ],
            'label' => Translator::getInstance()->trans('Lastname'),
            'label_attr' => [
                'for' => 'lastname',
            ],
        ]);

        $this->formBuilder
            ->add('email', EmailType::class, [
                'constraints' => [
                    new Constraints\NotBlank(),
                    new Constraints\Email(),
                    new Constraints\Callback(
                        [$this, 'verifyExistingEmail']
                    ),
                ],
                'label' => Translator::getInstance()->trans('Email'),
                'label_attr' => [
                    'for' => 'email',
                ],
            ]);

        $this->formBuilder->add('password', PasswordType::class, [
            'constraints' => [
                new NotBlank(),
                new PasswordStrength([
                    'minScore' => 1,
                ]),
            ],
            'label' => Translator::getInstance()->trans('Password'),
            'label_attr' => [
                'for' => 'password',
            ],
            'attr' => [
                'password_control' => true,
            ],
        ]);
    }

    public function verifyExistingEmail($value, ExecutionContextInterface $context): void
    {
        $customer = CustomerQuery::create()->findOneByEmail($value);
        if ($customer) {
            $context->addViolation($this->translation->trans('This email is already used'));
        }
    }

    public static function getName(): string
    {
        return 'flexybundle_form_customer_register_form';
    }
}
