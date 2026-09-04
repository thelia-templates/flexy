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
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfonycasts\DynamicForms\DependentField;
use Symfonycasts\DynamicForms\DynamicFormBuilder;
use Thelia\Core\Translation\Translator;
use Thelia\Domain\Customer\Service\CustomerTitleService;
use Thelia\Domain\Legal\CompanyIdentifier;
use Thelia\Domain\Legal\CompanyIdentifierLabels;
use Thelia\Domain\Legal\CompanyIdentifierRules;
use Thelia\Domain\Localization\Service\CountryService;
use Thelia\Form\AddressCountryValidationTrait;
use Thelia\Form\AddressCreateForm;
use Thelia\Model\CountryQuery;
use Thelia\Model\StateQuery;

/**
 * What someone ordering without an account is asked for.
 *
 * The buyer is asked once — title, name, email — and the delivery address is built from
 * that identity, which is why this form inherits the address fields rather than repeating
 * them. The billing address is the same one unless the buyer says otherwise.
 *
 * The billing block is a set of dependent fields rather than a block hidden in the
 * browser: what the buyer was not asked for is not on the page, is not submitted, and
 * cannot be validated against. The same mechanism already carries `state` off `country`
 * and the legal identifiers off `company` in this theme.
 *
 * The fields the shop requires of someone registering are required here too: an order
 * placed without an account still has to be deliverable and invoiceable.
 */
class GuestCheckoutForm extends AddressCreateForm
{
    use AddressCountryValidationTrait;

    public const FORM_NAME = 'flexybundle_form_guest_checkout';

    /**
     * The fields of the billing block, without their `invoice_` prefix — the names the
     * address creation reads them back under.
     */
    public const INVOICE_FIELDS = [
        'title',
        'firstname',
        'lastname',
        'company',
        'siret',
        'vat_number',
        'address1',
        'address2',
        'zipcode',
        'city',
        'country',
        'state',
        'phone',
    ];

    private const INVOICE_PREFIX = 'invoice_';

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

        // The delivery block is the buyer's own address: no label to pick, no default flag
        // to set — a guest has a single address book entry — and no company details, which
        // belong to the invoice.
        foreach (['label', 'is_default', 'address3', 'company', 'siret', 'vat_number'] as $unusedField) {
            $this->formBuilder->remove($unusedField);
        }

        $this->formBuilder = new DynamicFormBuilder($this->formBuilder);

        $this->addEmailField();
        $this->addDeliveryStateField();
        $this->makeCellphoneRequired();

        $this->formBuilder->add('invoice_same', CheckboxType::class, [
            'required' => false,
            'data' => true,
            'label' => $this->translation->trans('The billing address is the same as the delivery address'),
            'label_attr' => ['for' => 'invoice_same'],
        ]);

        $this->addBillingAddressFields();
        $this->addPrivacyPolicyField();
    }

    public static function getName(): string
    {
        return self::FORM_NAME;
    }

    private function addEmailField(): void
    {
        $this->formBuilder->add('email', EmailType::class, [
            'constraints' => [
                new Constraints\NotBlank(),
                new Constraints\Email(),
                new Constraints\Length(max: 255),
            ],
            'label' => $this->translation->trans('Email'),
            'label_attr' => ['for' => 'email'],
        ]);
    }

    /**
     * The order confirmation and the tracking link go to the address above and nowhere
     * else, so the buyer also has to be reachable on a phone the carrier can use.
     */
    private function makeCellphoneRequired(): void
    {
        $this->formBuilder->remove('cellphone');
        $this->formBuilder->add('cellphone', TelType::class, [
            'required' => true,
            'constraints' => [new NotBlank()],
            'label' => Translator::getInstance()->trans('Mobile phone'),
            'label_attr' => ['for' => 'cellphone'],
        ]);
    }

    /**
     * `required` only marks the field in the browser: an unchecked box submits nothing at
     * all, and nothing is what a form ignores. The constraint is what actually refuses the
     * submission — this is the consent the order is placed under.
     */
    private function addPrivacyPolicyField(): void
    {
        $this->formBuilder->add('accept_privacy_policy', CheckboxType::class, [
            'required' => true,
            'constraints' => [new Constraints\IsTrue()],
            'label' => $this->translation->trans('I agree to our privacy policy'),
            'label_attr' => ['for' => 'accept_privacy_policy'],
        ]);
    }

    /**
     * The delivery state, rebuilt from the delivery country. The core trait can be used
     * as-is here: it reads `country` and `state` off the root data by those exact names.
     */
    private function addDeliveryStateField(): void
    {
        $this->formBuilder->remove('state');

        $this->formBuilder->addDependent('state', 'country', function (DependentField $field, ?int $countryId): void {
            $stateChoices = null === $countryId ? [] : $this->getStatesChoices($countryId);

            if ([] === $stateChoices) {
                $field->add(HiddenType::class);

                return;
            }

            $field->add(ChoiceType::class, [
                'required' => false,
                'constraints' => [new Callback($this->verifyState(...))],
                'choices' => $stateChoices,
                'label' => Translator::getInstance()->trans('State'),
                'label_attr' => ['for' => 'state'],
                'placeholder' => Translator::getInstance()->trans('Select your state'),
            ]);
        });
    }

    /**
     * The billing block: present only when the buyer said the two addresses differ.
     */
    private function addBillingAddressFields(): void
    {
        $this->addWhenBillingDiffers('title', ChoiceType::class, fn (): array => [
            'required' => true,
            'constraints' => [new NotBlank()],
            'choices' => $this->customerTitleService->getTitleAsFormChoices(),
            'label' => Translator::getInstance()->trans('Title'),
        ]);

        $this->addWhenBillingDiffers('firstname', TextType::class, fn (): array => [
            'required' => true,
            'constraints' => [new NotBlank()],
            'label' => Translator::getInstance()->trans('First Name'),
        ]);

        $this->addWhenBillingDiffers('lastname', TextType::class, fn (): array => [
            'required' => true,
            'constraints' => [new NotBlank()],
            'label' => Translator::getInstance()->trans('Last Name'),
        ]);

        $this->addWhenBillingDiffers('company', TextType::class, fn (): array => [
            'required' => false,
            'label' => Translator::getInstance()->trans('Company Name'),
        ]);

        $this->addWhenBillingDiffers('address1', TextType::class, fn (): array => [
            'required' => true,
            'constraints' => [new NotBlank()],
            'label' => Translator::getInstance()->trans('Street Address'),
        ]);

        $this->addWhenBillingDiffers('address2', TextType::class, fn (): array => [
            'required' => false,
            'label' => Translator::getInstance()->trans('Address Line 2'),
        ]);

        $this->addWhenBillingDiffers('zipcode', TextType::class, fn (): array => [
            'required' => true,
            'constraints' => [new NotBlank(), new Callback($this->verifyBillingZipCode(...))],
            'label' => Translator::getInstance()->trans('Zip code'),
        ]);

        $this->addWhenBillingDiffers('city', TextType::class, fn (): array => [
            'required' => true,
            'constraints' => [new NotBlank()],
            'label' => Translator::getInstance()->trans('City'),
        ]);

        $this->addWhenBillingDiffers('country', ChoiceType::class, fn (): array => [
            'required' => true,
            'constraints' => [new NotBlank()],
            'choices' => $this->countryService->getAllCountriesChoiceType(),
            'data' => $this->countryService->getDefaultCountry()->getId(),
            'label' => Translator::getInstance()->trans('Country'),
        ]);

        $this->addWhenBillingDiffers('phone', TelType::class, fn (): array => [
            'required' => false,
            'label' => Translator::getInstance()->trans('Phone'),
        ]);

        $this->addBillingStateField();
        $this->addBillingLegalIdentifierFields();
    }

    /**
     * @param callable(): array<string, mixed> $options
     */
    private function addWhenBillingDiffers(string $field, string $type, callable $options): void
    {
        $name = self::INVOICE_PREFIX.$field;

        $this->formBuilder->addDependent(
            $name,
            'invoice_same',
            static function (DependentField $dependentField, mixed $billingIsTheSame) use ($name, $type, $options): void {
                if (self::billingIsTheSame($billingIsTheSame)) {
                    return;
                }

                $dependentField->add($type, [...$options(), 'label_attr' => ['for' => $name]]);
            },
        );
    }

    private function addBillingStateField(): void
    {
        $this->formBuilder->addDependent(
            'invoice_state',
            ['invoice_same', 'invoice_country'],
            function (DependentField $field, mixed $billingIsTheSame, mixed $countryId): void {
                if (self::billingIsTheSame($billingIsTheSame)) {
                    return;
                }

                $stateChoices = null === $countryId || '' === $countryId
                    ? []
                    : $this->getStatesChoices((int) $countryId);

                if ([] === $stateChoices) {
                    $field->add(HiddenType::class);

                    return;
                }

                $field->add(ChoiceType::class, [
                    'required' => true,
                    'constraints' => [new Callback($this->verifyBillingState(...))],
                    'choices' => $stateChoices,
                    'label' => Translator::getInstance()->trans('State'),
                    'label_attr' => ['for' => 'invoice_state'],
                    'placeholder' => Translator::getInstance()->trans('Select your state'),
                ]);
            },
        );
    }

    /**
     * The identifiers only appear once a company name is typed, and what they are called
     * depends on the country — the same rule the delivery form applies through
     * LegalIdentifierFieldsTrait, spelled out here for the prefixed field names.
     */
    private function addBillingLegalIdentifierFields(): void
    {
        foreach (['siret' => $this->verifyBillingSiret(...), 'vat_number' => $this->verifyBillingVatNumber(...)] as $field => $check) {
            $name = self::INVOICE_PREFIX.$field;

            $this->formBuilder->addDependent(
                $name,
                ['invoice_same', 'invoice_company', 'invoice_country'],
                static function (DependentField $dependentField, mixed $billingIsTheSame, mixed $company, mixed $countryId) use ($name, $field, $check): void {
                    if (self::billingIsTheSame($billingIsTheSame)
                        || !CompanyIdentifier::hasCompany(\is_string($company) ? $company : null)
                    ) {
                        return;
                    }

                    $countryCode = self::countryCodeOf($countryId);

                    $dependentField->add(TextType::class, [
                        'required' => true,
                        'constraints' => [new Callback($check)],
                        'label' => Translator::getInstance()->trans(
                            'siret' === $field
                                ? CompanyIdentifierLabels::siret($countryCode)
                                : CompanyIdentifierLabels::vatNumber($countryCode),
                        ),
                        'label_attr' => ['for' => $name],
                    ]);
                },
            );
        }
    }

    public function verifyBillingZipCode(mixed $value, ExecutionContextInterface $context): void
    {
        $data = $context->getRoot()->getData();
        $country = CountryQuery::create()->findPk($data['invoice_country'] ?? null);
        $zipCodeRegExp = $country?->getZipCodeRE();

        if (null === $country || !$country->getNeedZipCode() || null === $zipCodeRegExp) {
            return;
        }

        if (!preg_match($zipCodeRegExp, (string) $value)) {
            $context->addViolation(Translator::getInstance()->trans(
                'This zip code should respect the following format : %format.',
                ['%format' => $country->getZipCodeFormat()],
            ));
        }
    }

    public function verifyBillingState(mixed $value, ExecutionContextInterface $context): void
    {
        $data = $context->getRoot()->getData();
        $country = CountryQuery::create()->findPk($data['invoice_country'] ?? null);

        if (null === $country) {
            return;
        }

        if (empty($value)) {
            // Requiring a state from a country that carries none would leave the buyer
            // with an empty list and no way to submit the form.
            if ($country->getHasStates() && $country->hasSelectableStates()) {
                $context->addViolation(Translator::getInstance()->trans('You should select a state for this country.'));
            }

            return;
        }

        $state = StateQuery::create()->findPk($value);

        // A state stays tied to its country: one left over from a country that was
        // changed in the same submit must not survive it.
        if (null === $state || $state->getCountryId() !== $country->getId()) {
            $context->addViolation(Translator::getInstance()->trans("This state doesn't belong to this country."));
        }
    }

    public function verifyBillingSiret(mixed $value, ExecutionContextInterface $context): void
    {
        foreach ($this->billingLegalIdentifierViolations($context) as $violation) {
            if ($violation->isAboutSiret()) {
                $context->addViolation(Translator::getInstance()->trans($violation->message, $violation->parameters));
            }
        }
    }

    public function verifyBillingVatNumber(mixed $value, ExecutionContextInterface $context): void
    {
        foreach ($this->billingLegalIdentifierViolations($context) as $violation) {
            if (!$violation->isAboutSiret()) {
                $context->addViolation(Translator::getInstance()->trans($violation->message, $violation->parameters));
            }
        }
    }

    /**
     * @return list<\Thelia\Domain\Legal\CompanyIdentifierViolation>
     */
    private function billingLegalIdentifierViolations(ExecutionContextInterface $context): array
    {
        $data = $context->getRoot()->getData();

        return CompanyIdentifierRules::violationsFor(
            self::stringOrNull($data, 'invoice_company'),
            self::stringOrNull($data, 'invoice_siret'),
            self::stringOrNull($data, 'invoice_vat_number'),
            self::countryCodeOf($data['invoice_country'] ?? null),
        );
    }

    /**
     * An unchecked box submits nothing, so what arrives here is "1" or nothing at all.
     * A missing dependency also means the box was never on the page — a first render —
     * and the billing block then stays folded away.
     */
    private static function billingIsTheSame(mixed $value): bool
    {
        return null === $value || '' === $value || (bool) $value;
    }

    private static function countryCodeOf(mixed $countryId): ?string
    {
        if (null === $countryId || '' === $countryId) {
            return null;
        }

        return CountryQuery::create()->findPk($countryId)?->getIsoalpha2();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function stringOrNull(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) ? $value : null;
    }
}
