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
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfonycasts\DynamicForms\DependentField;
use Symfonycasts\DynamicForms\DynamicFormBuilder;
use Thelia\Core\Translation\Translator;
use Thelia\Domain\Customer\Service\CustomerTitleService;
use Thelia\Domain\Localization\Service\CountryService;
use Thelia\Form\AddressCountryValidationTrait;
use Thelia\Form\AddressCreateForm;
use Thelia\Model\CountryQuery;

class AddressEditForm extends AddressCreateForm
{
    use AddressCountryValidationTrait;
    use LegalIdentifierFieldsTrait;

    public const FORM_NAME = 'flexybundle_address_edit_form';

    public function __construct(
        CountryService $countryService,
        CustomerTitleService $customerTitleService,
        #[Autowire(service: 'translator')]
        private readonly TranslatorInterface $themeTranslator,
    ) {
        parent::__construct($countryService, $customerTitleService);
    }

    public function buildForm(): void
    {
        parent::buildForm();

        $this->formBuilder = new DynamicFormBuilder($this->formBuilder);

        $this->formBuilder->remove('state');

        // The state list depends on the selected country: rebuild the field
        // whenever the country changes (hidden when the country has no state).
        $this->formBuilder->addDependent('state', 'country', function (DependentField $field, ?int $countryId): void {
            if (null === $countryId) {
                $field->add(HiddenType::class);

                return;
            }

            $stateChoices = $this->getStatesChoices($countryId);

            if (empty($stateChoices)) {
                $field->add(HiddenType::class);

                return;
            }

            $field->add(ChoiceType::class, [
                // Required only where the country asks for one (verifyState() of the core): the departments of
                // France are listed but optional.
                'required' => (bool) CountryQuery::create()->findPk($countryId)?->getHasStates(),
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

        // Kept out of the forms of a customer who belongs to no company, and of the checkout.
        $this->formBuilder->add('share_with_company', CheckboxType::class, [
            'required' => false,
            'label' => $this->themeTranslator->trans('Share this address with my company'),
        ]);
    }

    public static function getName(): string
    {
        return self::FORM_NAME;
    }
}
