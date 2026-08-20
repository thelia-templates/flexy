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
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfonycasts\DynamicForms\DependentField;
use Symfonycasts\DynamicForms\DynamicFormBuilder;
use Thelia\Core\Translation\Translator;
use Thelia\Domain\Customer\Service\CustomerTitleService;
use Thelia\Domain\Localization\Service\CountryService;
use Thelia\Form\AddressCreateForm;

class CustomerInformationsForm extends AddressCreateForm
{
    use LegalIdentifierFieldsTrait;

    public function __construct(
        protected CountryService $countryService,
        protected CustomerTitleService $customerTitleService,
        #[Autowire(service: 'translator')]
        private readonly TranslatorInterface $translation,
    ) {
        parent::__construct($countryService, $customerTitleService);
    }

    protected function buildForm(): void
    {
        parent::buildForm();

        $this->formBuilder = new DynamicFormBuilder($this->formBuilder);

        $this->formBuilder->remove('is_default');
        $this->formBuilder->remove('cellphone');
        $this->formBuilder->remove('state');

        $this->formBuilder->addDependent('state', 'country', function (DependentField $field, ?int $countryId) {
            if (null === $countryId) {
                return;
            }

            $stateChoices = $this->getStatesChoices($countryId);

            if (empty($stateChoices)) {
                return;
            }

            $field->add(ChoiceType::class, [
                'required' => false,
                'constraints' => [
                    new Callback($this->verifyState(...)),
                ],
                'choices' => $stateChoices,
                'label' => Translator::getInstance()->trans('State'),
                'label_attr' => [
                    'for' => 'state',
                ],
                'placeholder' => Translator::getInstance()->trans('Select your state'),
            ]);
        });

        $this->addLegalIdentifierFields();

        $this->formBuilder->add('is_default', HiddenType::class, [
            'data' => true,
        ])->add('cellphone', TelType::class, [
            'label' => Translator::getInstance()->trans('Mobile phone'),
            'label_attr' => [
                'for' => 'cellphone',
            ],
            'required' => true,
        ])->add(
            'newsletter',
            CheckboxType::class,
            [
                'required' => false,
                'label' => $this->translation->trans('I agree to receive promotional offers by newsletter'),
                'label_attr' => [
                    'for' => 'newsletter',
                ],
            ]
        )->add(
            'accept_privacy_policy',
            CheckboxType::class,
            [
                'label' => $this->translation->trans('I agree to our privacy policy'),
                'label_attr' => [
                    'for' => 'accept_privacy_policy',
                ],
                'required' => true,
            ]
        );
    }

    public static function getName(): string
    {
        return 'flexybundle_form_customer_informations_form';
    }
}
