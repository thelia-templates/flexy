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
use Thelia\Model\ConfigQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;

/**
 * The sign-up form says an address already has an account, so it is a probe for the
 * registered addresses: its attempts are counted per address and per caller, whatever
 * their outcome, and a caller over budget gets an answer that says nothing about the address.
 */
final class RegistrationRateLimitTest extends WebIntegrationTestCase
{
    private const string PASSWORD = 'Str0ng-Passw0rd!xyz';
    private const int PER_ADDRESS = 5;
    private const int PER_CLIENT = 20;

    private string $previousEmailConfirmation = '';

    protected function setUp(): void
    {
        parent::setUp();

        // An account waiting for its activation code leaves the visitor signed out, so the
        // next post is a sign-up again and not a redirect for someone already in.
        $this->previousEmailConfirmation = (string) ConfigQuery::read('customer_email_confirmation', '0');
        ConfigQuery::write('customer_email_confirmation', '1');

        // The counters outlive the run: every test starts with a full budget.
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function tearDown(): void
    {
        ConfigQuery::write('customer_email_confirmation', $this->previousEmailConfirmation);
        static::getContainer()->get('cache.rate_limiter')->clear();

        parent::tearDown();
    }

    public function testAnAddressOverItsBudgetGetsTheNeutralMessageAndNothingIsCreated(): void
    {
        $crawler = $this->client->request('GET', '/customer/register');
        self::assertResponseIsSuccessful();

        // Failed attempts (an address that is not one) spend the budget of the address too.
        for ($attempt = 1; $attempt <= self::PER_ADDRESS; ++$attempt) {
            $this->post($crawler, 'Target@Example.test');
        }

        $this->post($crawler, ' target@example.test');
        $message = $this->messageAfter();

        self::assertStringContainsString('Too many attempts. Please try again later.', $message);
        self::assertStringNotContainsString('already used', $message);
    }

    public function testARegisteredAddressAndAnUnknownOneAreAnsweredAlikeOnceTheCallerIsOverBudget(): void
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $factory->customer($factory->customerTitle(), ['email' => 'registered-probe@example.test']);
        $crawler = $this->client->request('GET', '/customer/register');

        for ($attempt = 1; $attempt <= self::PER_CLIENT; ++$attempt) {
            $this->post($crawler, 'filler-'.$attempt.'@example.test');
        }

        $this->post($crawler, 'registered-probe@example.test');
        $registered = $this->messageAfter();
        $this->post($crawler, 'unknown-probe@example.test');
        $unknown = $this->messageAfter();

        self::assertStringContainsString('Too many attempts. Please try again later.', $registered);
        self::assertSame($unknown, $registered);
        self::assertNull(CustomerQuery::create()->findOneByEmail('unknown-probe@example.test'));
    }

    public function testTheGroupLinkFormSharesTheBudget(): void
    {
        $group = (new FixtureFactory($this->getPropelConnection()))->customerGroup('rl-pro', openRegistration: true);
        $url = '/register/'.$group->getRegistrationToken();
        $crawler = $this->client->request('GET', $url);

        for ($attempt = 1; $attempt <= self::PER_ADDRESS; ++$attempt) {
            $this->post($crawler, 'shared@example.test', $url);
        }

        $this->post($crawler, 'shared@example.test', $url);
        self::assertStringContainsString('Too many attempts. Please try again later.', $this->messageAfter());
    }

    public function testAnAddressUnderItsBudgetRegisters(): void
    {
        $crawler = $this->client->request('GET', '/customer/register');
        $this->post($crawler, 'fresh@example.test');

        self::assertNotNull(CustomerQuery::create()->findOneByEmail('fresh@example.test'));
    }

    /**
     * What the visitor reads as the error after the last post: the page it was sent back to.
     */
    private function messageAfter(): string
    {
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($location, 'A refused sign-up sends the visitor back to a page.');

        return trim($this->client->request('GET', $location)->filter('.Input-error')->text(''));
    }

    private function post(Crawler $page, string $email, string $url = '/customer/register'): void
    {
        $name = CustomerRegisterForm::getName();

        $this->client->request('POST', $url, [
            $name => [
                'firstname' => 'Pat',
                'lastname' => 'Pro',
                'email' => $email,
                'password' => self::PASSWORD,
                'success_url' => '/customer/informations',
                'error_url' => '',
                '_token' => (string) $page->filter('input[name="'.$name.'[_token]"]')->attr('value'),
            ],
        ]);
    }
}
