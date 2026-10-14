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

namespace FlexyBundle\Tests\Integration;

use FlexyBundle\Form\AddressEditForm;
use FlexyBundle\Form\GuestCheckoutForm;
use Symfony\Component\Form\FormInterface;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CountryQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * Once a company is typed, the legal identifiers appear on the address form and are required, unless the shop sets
 * `address_legal_identifiers_required` to 0: then an empty field passes, and a typed one is still checked. Runs on an
 * installed shop (bin/test-prepare).
 */
final class LegalIdentifiersRequirementTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        ConfigQuery::resetCache();
        parent::tearDown();
    }

    public function testACompanyWithoutIdentifiersIsRefusedByDefault(): void
    {
        $form = $this->submitted(['company' => 'Garage', 'siret' => '', 'vat_number' => '']);

        self::assertTrue($form->get('siret')->getConfig()->getOption('required'));
        self::assertFalse($form->get('siret')->isValid());
        self::assertFalse($form->get('vat_number')->isValid());
        self::assertTrue($form->get('vat_number')->getConfig()->getOption('required'));
    }

    public function testACompanyWithoutIdentifiersPassesWhenTheShopLeavesThemOptional(): void
    {
        ConfigQuery::write(AddressEditForm::LEGAL_IDENTIFIERS_REQUIRED_CONFIG_KEY, '0');

        $form = $this->submitted(['company' => 'Garage', 'siret' => '', 'vat_number' => '']);

        self::assertFalse($form->get('siret')->getConfig()->getOption('required'));
        self::assertTrue($form->get('siret')->isValid());
        self::assertFalse($form->get('vat_number')->getConfig()->getOption('required'));
        self::assertTrue($form->get('vat_number')->isValid());
    }

    public function testAnyValueOtherThanZeroKeepsTheIdentifiersRequired(): void
    {
        ConfigQuery::write(AddressEditForm::LEGAL_IDENTIFIERS_REQUIRED_CONFIG_KEY, '');

        $form = $this->submitted(['company' => 'Garage', 'siret' => '', 'vat_number' => '']);

        self::assertFalse($form->get('siret')->isValid());
        self::assertFalse($form->get('vat_number')->isValid());
    }

    public function testTheGuestBillingIdentifiersAreMarkedRequiredByDefault(): void
    {
        $form = $this->guestSubmitted();

        self::assertTrue($form->get('invoice_siret')->getConfig()->getOption('required'));
        self::assertTrue($form->get('invoice_vat_number')->getConfig()->getOption('required'));
    }

    public function testTheGuestBillingIdentifiersAreMarkedOptionalWhenTheShopLeavesThemOptional(): void
    {
        ConfigQuery::write(GuestCheckoutForm::LEGAL_IDENTIFIERS_REQUIRED_CONFIG_KEY, '0');

        $form = $this->guestSubmitted();

        self::assertFalse($form->get('invoice_siret')->getConfig()->getOption('required'));
        self::assertFalse($form->get('invoice_vat_number')->getConfig()->getOption('required'));
    }

    public function testATypedIdentifierIsStillCheckedWhenOptional(): void
    {
        ConfigQuery::write(AddressEditForm::LEGAL_IDENTIFIERS_REQUIRED_CONFIG_KEY, '0');

        $form = $this->submitted(['company' => 'Garage', 'siret' => '123', 'vat_number' => '']);

        self::assertFalse($form->get('siret')->isValid());
    }

    private function guestSubmitted(): FormInterface
    {
        $france = CountryQuery::create()->findOneByIsoalpha2('FR') ?? self::markTestSkipped('No France in the shop.');

        /** @var FormServiceInterface $forms */
        $forms = $this->getService(FormServiceInterface::class);
        $form = $forms->getFormByName(GuestCheckoutForm::FORM_NAME);
        $form->submit([
            'invoice_same' => false,
            'invoice_company' => 'Garage',
            'invoice_country' => (string) $france->getId(),
        ], false);

        return $form;
    }

    /**
     * @param array<string, string> $values
     */
    private function submitted(array $values): FormInterface
    {
        $france = CountryQuery::create()->findOneByIsoalpha2('FR') ?? self::markTestSkipped('No France in the shop.');

        /** @var FormServiceInterface $forms */
        $forms = $this->getService(FormServiceInterface::class);
        $form = $forms->getFormByName(AddressEditForm::FORM_NAME);
        $form->submit($values + [
            'label' => 'Office',
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'address1' => '1 Main Street',
            'zipcode' => '75001',
            'city' => 'Paris',
            'country' => (string) $france->getId(),
        ], false);

        return $form;
    }
}
