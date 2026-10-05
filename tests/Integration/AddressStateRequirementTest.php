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
use Symfony\Component\Form\FormInterface;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Model\Country;
use Thelia\Model\CountryQuery;
use Thelia\Model\StateQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The state of an address is required where the country says it has states, as the core
 * validates it (AddressCountryValidationTrait::verifyState()): a country whose states are
 * listed but not required (the departments of France) gets an optional field, not a
 * required one with a star the core does not enforce. Runs on an installed shop (bin/test-prepare).
 */
final class AddressStateRequirementTest extends IntegrationTestCase
{
    public function testTheStateIsOptionalWhereTheCountryListsStatesWithoutRequiringThem(): void
    {
        $country = $this->countryWithStates(false);

        self::assertFalse($this->stateField($country)->getConfig()->getOption('required'));
    }

    public function testTheStateIsRequiredWhereTheCountryHasStates(): void
    {
        $country = $this->countryWithStates(true);

        self::assertTrue($this->stateField($country)->getConfig()->getOption('required'));
    }

    private function stateField(Country $country): FormInterface
    {
        /** @var FormServiceInterface $forms */
        $forms = $this->getService(FormServiceInterface::class);
        $form = $forms->getFormByName(AddressEditForm::FORM_NAME, ['country' => $country->getId()]);

        self::assertTrue($form->has('state'));

        return $form->get('state');
    }

    private function countryWithStates(bool $hasStates): Country
    {
        foreach (CountryQuery::create()->filterByHasStates($hasStates)->find() as $country) {
            if (StateQuery::create()->filterByCountryId($country->getId())->filterByVisible(1)->count() > 0) {
                return $country;
            }
        }

        self::markTestSkipped('No country of the shop lists visible states with has_states = '.(int) $hasStates.'.');
    }
}
