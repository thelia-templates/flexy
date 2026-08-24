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

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\Callback;
use Symfonycasts\DynamicForms\DependentField;
use Thelia\Core\Translation\Translator;
use Thelia\Domain\Legal\CompanyIdentifier;
use Thelia\Domain\Legal\CompanyIdentifierLabels;
use Thelia\Model\CountryQuery;

/**
 * Turns the two legal identifier fields inherited from AddressCreateForm into fields that
 * only appear once a company name is typed.
 *
 * Uses the dependent-field mechanism already carrying `state` off `country` in this theme,
 * rather than hiding the fields in the browser: the form that reaches the server then holds
 * exactly the fields the buyer was asked for. The server-side rule in
 * AddressLegalIdentifiersValidationTrait still decides, so a forged post gains nothing.
 *
 * They also depend on `country`, because what the identifiers are called changes with it.
 */
trait LegalIdentifierFieldsTrait
{
    private function addLegalIdentifierFields(): void
    {
        $this->formBuilder->remove('siret');
        $this->formBuilder->remove('vat_number');

        $this->formBuilder->addDependent(
            'siret',
            ['company', 'country'],
            function (DependentField $field, mixed $company, mixed $countryId): void {
                if (!CompanyIdentifier::hasCompany(\is_string($company) ? $company : null)) {
                    return;
                }

                $field->add(TextType::class, [
                    'required' => true,
                    'constraints' => [new Callback($this->verifySiret(...))],
                    'label' => Translator::getInstance()->trans(
                        CompanyIdentifierLabels::siret(self::legalIdentifierCountryCode($countryId)),
                    ),
                    'label_attr' => ['for' => 'siret'],
                ]);
            },
        );

        $this->formBuilder->addDependent(
            'vat_number',
            ['company', 'country'],
            function (DependentField $field, mixed $company, mixed $countryId): void {
                if (!CompanyIdentifier::hasCompany(\is_string($company) ? $company : null)) {
                    return;
                }

                $field->add(TextType::class, [
                    'required' => true,
                    'constraints' => [new Callback($this->verifyVatNumber(...))],
                    'label' => Translator::getInstance()->trans(
                        CompanyIdentifierLabels::vatNumber(self::legalIdentifierCountryCode($countryId)),
                    ),
                    'label_attr' => ['for' => 'vat_number'],
                ]);
            },
        );
    }

    private static function legalIdentifierCountryCode(mixed $countryId): ?string
    {
        if (null === $countryId || '' === $countryId) {
            return null;
        }

        return CountryQuery::create()->findPk($countryId)?->getIsoalpha2();
    }
}
