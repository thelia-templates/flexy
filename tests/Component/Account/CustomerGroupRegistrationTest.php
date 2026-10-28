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

namespace FlexyBundle\Tests\Component\Account;

use FlexyBundle\Form\CustomerRegisterForm;
use Symfony\Component\DomCrawler\Crawler;
use Thelia\Domain\Customer\Service\CustomerGroupRegistration;
use Thelia\Model\CustomerGroupQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;

/**
 * The sign-up form a customer group hands out through a secret link, from a browser's point
 * of view: the standard form, served at /register/{token}, whose new account joins the group.
 *
 * The token is the one of the URL and is checked again at every request: a link that was
 * regenerated, closed or never issued serves nothing and writes nothing.
 */
final class CustomerGroupRegistrationTest extends WebIntegrationTestCase
{
    private const PASSWORD = 'Str0ng-Passw0rd!xyz';

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        // Not createFixtureFactory(): it leaves a request on the stack, in front of the ones the client sends.
        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    public function testAnOpenLinkServesTheStandardFormAndTheNewAccountJoinsTheGroup(): void
    {
        $group = $this->factory->customerGroup('m3-pro', openRegistration: true);
        $token = (string) $group->getRegistrationToken();

        $crawler = $this->client->request('GET', '/register/'.$token);

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action="/register/'.$token.'"]');
        self::assertCount(1, $form, 'The form posts back to the link it was served from.');
        foreach (['firstname', 'lastname', 'email', 'password'] as $field) {
            self::assertCount(1, $form->filter('[name="'.CustomerRegisterForm::getName().'['.$field.']"]'), 'The fields are the ones of the standard form.');
        }
        self::assertCount(0, $form->filter('input[value*="'.$token.'"]'), 'No field carries the token: the URL is the only place it is read from.');
        self::assertCount(0, $form->filter('[name*="group"], [name*="token"]:not([name$="[_token]"])'), 'No field names a group.');

        $this->post($crawler, '/register/'.$token, 'pro@example.test');

        $customer = CustomerQuery::create()->findOneByEmail('pro@example.test');
        self::assertNotNull($customer, 'The account was created.');
        self::assertSame((int) $group->getId(), $customer->getCustomerGroupId());
    }

    public function testAFailedSignUpComesBackToTheLinkAndCreatesNothing(): void
    {
        $group = $this->factory->customerGroup('m3-back', openRegistration: true);
        $url = '/register/'.$group->getRegistrationToken();

        $crawler = $this->client->request('GET', $url);
        $this->post($crawler, $url, 'not-an-email');

        self::assertResponseRedirects($url);
        self::assertNull(CustomerQuery::create()->findOneByEmail('not-an-email'));
    }

    public function testTheStandardFormStillRegistersInTheDefaultGroup(): void
    {
        $this->factory->customerGroup('m3-other', openRegistration: true);

        $crawler = $this->client->request('GET', '/customer/register');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action*="/register/"]'));

        $this->post($crawler, '/customer/register', 'default@example.test');

        $customer = CustomerQuery::create()->findOneByEmail('default@example.test');
        self::assertNotNull($customer);
        self::assertSame((int) CustomerGroupQuery::findDefault()->getId(), $customer->getCustomerGroupId());
    }

    public function testAGroupNamedInThePostedBodyIsIgnored(): void
    {
        $pro = $this->factory->customerGroup('m3-body-pro', openRegistration: true);
        $other = $this->factory->customerGroup('m3-body-other', openRegistration: true);
        $token = (string) $pro->getRegistrationToken();

        $crawler = $this->client->request('GET', '/register/'.$token);
        $this->post($crawler, '/register/'.$token, 'forged@example.test', [
            'customer_group_id' => (string) $other->getId(),
            'registrationToken' => (string) $other->getRegistrationToken(),
            'registration_token' => (string) $other->getRegistrationToken(),
            'token' => (string) $other->getRegistrationToken(),
        ]);

        self::assertSame((int) $pro->getId(), CustomerQuery::create()->findOneByEmail('forged@example.test')?->getCustomerGroupId(), 'Only the URL names the group.');
    }

    public function testALinkThatOpensNothingServesNothingAndCreatesNothing(): void
    {
        $registration = $this->getService(CustomerGroupRegistration::class);
        $regenerated = $this->factory->customerGroup('m3-regenerated', openRegistration: true);
        $closed = $this->factory->customerGroup('m3-closed', openRegistration: true);
        $oldToken = (string) $regenerated->getRegistrationToken();
        $closedToken = (string) $closed->getRegistrationToken();

        // The forms were served while the links were open: their posts come after the links went.
        $oldForm = $this->client->request('GET', '/register/'.$oldToken);
        self::assertResponseIsSuccessful();
        $closedForm = $this->client->request('GET', '/register/'.$closedToken);
        self::assertResponseIsSuccessful();

        $newToken = $registration->regenerate($regenerated);
        $registration->close($closed);
        self::assertNotSame($oldToken, $newToken);

        $this->client->request('GET', '/register/'.$newToken);
        self::assertResponseIsSuccessful('The new link works.');

        $links = [
            'a regenerated link' => ['/register/'.$oldToken, $oldForm, 'regenerated@example.test'],
            'a closed link' => ['/register/'.$closedToken, $closedForm, 'closed@example.test'],
            'a link never issued' => ['/register/'.str_repeat('a', 64), $oldForm, 'unissued@example.test'],
            'a malformed link' => ['/register/not-a-token', $oldForm, 'malformed@example.test'],
        ];
        foreach ($links as $label => [$url, $page, $email]) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $label.' serves no page');

            $this->post($page, $url, $email);
            self::assertResponseStatusCodeSame(404, $label.' accepts no sign-up');
            self::assertNull(CustomerQuery::create()->findOneByEmail($email), $label.' creates nothing');
        }
    }

    /**
     * @param array<string, string> $extra fields added to the body, outside the form
     */
    private function post(Crawler $page, string $url, string $email, array $extra = []): void
    {
        $name = CustomerRegisterForm::getName();
        $token = (string) $page->filter('input[name="'.$name.'[_token]"]')->attr('value');

        $this->client->request('POST', $url, [
            $name => [
                'firstname' => 'Pat',
                'lastname' => 'Pro',
                'email' => $email,
                'password' => self::PASSWORD,
                'success_url' => '/customer/informations',
                'error_url' => '',
                '_token' => $token,
            ],
        ] + $extra);
    }
}
