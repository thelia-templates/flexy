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

use FlexyBundle\Service\FormService;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Validator\Constraints\Callback;
use Symfonycasts\DynamicForms\DependentField;
use Symfonycasts\DynamicForms\DynamicFormBuilder;
use Thelia\Core\Translation\Translator;
use Thelia\Domain\Customer\Service\CustomerTitleService;
use Thelia\Domain\Localization\Service\CountryService;
use Thelia\Form\AddressCountryValidationTrait;
use Thelia\Form\AddressCreateForm;

class AddressEditForm extends AddressCreateForm
{
    use AddressCountryValidationTrait;

    public const FORM_NAME = 'flexybundle_address_edit_form';

    public function __construct(
        CountryService $countryService,
        CustomerTitleService $customerTitleService,
        private readonly FormService $formService,
    ) {
        parent::__construct($countryService, $customerTitleService);
    }

    public function buildForm(): void
    {
        parent::buildForm();

        $this->formBuilder = new DynamicFormBuilder($this->formBuilder);

        $this->formBuilder->remove('state');

        $this->formBuilder->addDependent('state', 'country', function (DependentField $field, int $countryId): void {
            if (null == $countryId) {
                $field->add(HiddenType::class);

                return;
            }
            $stateChoices = $this->getStatesChoices($countryId);
            if (empty($stateChoices)) {
                $field->add(HiddenType::class);

                return;
            }

            $field->add(ChoiceType::class, [
                'required' => true,
                'constraints' => [
                    new Callback(
                        $this->verifyState(...),
                    ),
                ],
                'choices' => $stateChoices,
                'label' => Translator::getInstance()->trans('State'),
                'label_attr' => [
                    'for' => 'state',
                ],
                'placeholder' => Translator::getInstance()->trans('Select your state'),
            ]);
        });
    }

    public static function getName(): string
    {
        return self::FORM_NAME;
    }
}
