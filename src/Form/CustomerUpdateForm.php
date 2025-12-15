<?php

declare(strict_types=1);

namespace FlexyBundle\Form;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Thelia\Model\ConfigQuery;
use Symfony\Component\Validator\Constraints;
use Symfony\Contracts\Translation\TranslatorInterface;

class CustomerUpdateForm extends CustomerRegisterForm
{
    public const FORM_NAME = 'flexybundle_form_customer_update_form';

    public function __construct(
        #[Autowire(service: 'translator')]
        public ?TranslatorInterface $translator
    )
    {}

    public function buildForm(): void
    {
        parent::buildForm();

        $this->formBuilder->remove('password');
        $this->formBuilder->remove('email');

        $canUpdateEmail = ConfigQuery::read('customer_change_email', 0);

        $this->formBuilder->add('email', EmailType::class, [
            'constraints' => [
                new Constraints\NotBlank(),
                new Constraints\Email(),
                new Constraints\Callback(
                    [$this, 'verifyExistingEmail']
                ),
            ],
            'label' => $this->translator->trans('Email'),
            'disabled' => !$canUpdateEmail,

            'help' => !$canUpdateEmail ? $this->translator->trans('Si vous voulez changer d\'adresse mail, contactez nous.') : null,

        ]);
    }

    public static function getName(): string
    {
        return self::FORM_NAME;
    }
}
