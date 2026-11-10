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

use FlexyBundle\Service\Subscription\AccountSubscriptions;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\Currency;
use Thelia\Model\Customer;
use Thelia\Model\CustomerPaymentMethod;
use Thelia\Model\Module;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Model\Subscription;
use Thelia\Model\SubscriptionOrder;
use Thelia\Model\SubscriptionPlan;
use Thelia\Model\SubscriptionPlanPrice;
use Thelia\Model\SubscriptionQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\Flexy\CustomerSessionInjector;
use Thelia\Tests\Support\Payment\StoredMethodsPaymentModule;
use Thelia\Tests\Support\Payment\UsesStoredMethodsPaymentModule;

/**
 * "My subscriptions" of the customer account, from a browser's point of view.
 *
 * What the theme owes the core: the pages list and show the signed-in customer's subscriptions and nobody else's (another
 * customer's id answers 404, a visitor goes to the sign-in), every answer is `no-store`, each action goes through the core
 * manager behind a CSRF token, and what the state refuses is a message on the page, never a 500.
 *
 * Needs the core's subscriptions: skipped on a core published without them. Nothing the core added is in the signature
 * of the class or of its properties, and the ones restored in tearDown() are nullable.
 */
final class AccountSubscriptionsTest extends WebIntegrationTestCase
{
    use UsesStoredMethodsPaymentModule;

    private ?FixtureFactory $factory = null;

    private ?CustomerSessionInjector $injector = null;

    private ?Product $product = null;

    private ?SubscriptionPlan $plan = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('Thelia\\Domain\\Subscription\\SubscriptionStateMachine')) {
            self::markTestSkipped('The core published does not carry the subscriptions yet.');
        }

        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->injector = new CustomerSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->product = null;
        $this->plan = null;
    }

    protected function tearDown(): void
    {
        if (null !== $this->injector) {
            $this->injector->clear();
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->uninstallTheStoredMethodsPaymentModule(static::getContainer());
        }

        $this->injector = null;
        $this->factory = null;
        $this->product = null;
        $this->plan = null;

        parent::tearDown();
    }

    public function testAVisitorIsSentToTheSignIn(): void
    {
        $this->client->request('GET', '/account/subscriptions');

        self::assertResponseRedirects();
        self::assertStringContainsString('/customer/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheListHoldsTheSubscriptionsOfTheCustomerOnlyAndIsNoStore(): void
    {
        $customer = $this->signedInCustomer();
        $mine = $this->subscriptionOf($customer);
        $other = $this->subscriptionOf($this->factory()->customer($this->factory()->customerTitle()));

        $crawler = $this->client->request('GET', '/account/subscriptions');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertCount(1, $crawler->filter('[data-testid="subscription"]'));
        self::assertStringContainsString((string) $mine->getRef(), $crawler->filter('[data-testid="subscription"]')->text());
        self::assertStringNotContainsString((string) $other->getRef(), $crawler->text());
    }

    public function testTheMenuOffersTheEntryToACustomerWhoHoldsASubscription(): void
    {
        $customer = $this->signedInCustomer();
        $this->subscriptionOf($customer);

        $crawler = $this->client->request('GET', '/account');

        self::assertGreaterThanOrEqual(1, $crawler->filter('a[href="/account/subscriptions"]')->count());
    }

    public function testTheMenuOffersNothingWhenTheShopSellsNoPlanAndTheCustomerHoldsNone(): void
    {
        $this->signedInCustomer();

        $crawler = $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href="/account/subscriptions"]'));
    }

    public function testTheDetailShowsTheValuesTheInstallmentsAndTheMethodLabel(): void
    {
        $customer = $this->signedInCustomer();
        $method = $this->methodOf($customer, $this->storedModule(), 'Visa ending 4242');
        $subscription = $this->subscriptionOf($customer, ['CustomerPaymentMethodId' => $method->getId(), 'CommitmentCount' => 3]);
        $this->installment($subscription, 1, 'paid');
        $this->installment($subscription, 2, 'failed');

        $crawler = $this->client->request('GET', '/account/subscriptions/'.$subscription->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $text = $crawler->filter('[data-testid="subscription-detail"]')->text();
        self::assertStringContainsString('Visa ending 4242', $text);
        self::assertStringContainsString('Commitment of 3 installments', $text);
        self::assertCount(2, $crawler->filter('[data-testid="subscription-installments"] tbody tr'));
    }

    public function testASubscriptionOfAnotherCustomerReadsAsOneThatDoesNotExist(): void
    {
        $customer = $this->signedInCustomer();
        $own = $this->subscriptionOf($customer);
        $other = $this->subscriptionOf($this->factory()->customer($this->factory()->customerTitle()));
        $token = $this->token($own);

        $this->client->request('GET', '/account/subscriptions/'.$other->getId());
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/account/subscriptions/'.$other->getId().'/cancel');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/account/subscriptions/'.$other->getId().'/cancel', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame('active', $this->reread($other)->getStatus(), 'Nothing happened to the other subscription.');
        self::assertSame('active', $this->reread($own)->getStatus());
    }

    public function testACustomerPausesThenResumesAPausablePlan(): void
    {
        $customer = $this->signedInCustomer();
        $subscription = $this->subscriptionOf($customer, ['Pausable' => 1]);
        $token = $this->token($subscription);

        $this->client->request('POST', '/account/subscriptions/'.$subscription->getId().'/pause', ['_token' => $token]);
        self::assertResponseRedirects();
        self::assertSame('paused', $this->reread($subscription)->getStatus());

        $this->client->request('POST', '/account/subscriptions/'.$subscription->getId().'/resume', ['_token' => $token]);
        self::assertResponseRedirects();
        self::assertSame('active', $this->reread($subscription)->getStatus());
    }

    public function testThePauseOfAPlanThatCannotBePausedIsAMessageAndNotAnError(): void
    {
        $customer = $this->signedInCustomer();
        $subscription = $this->subscriptionOf($customer, ['Pausable' => 0]);

        $this->client->request('POST', '/account/subscriptions/'.$subscription->getId().'/pause', ['_token' => $this->token($subscription)]);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->reread($subscription)->getStatus());
        self::assertCount(1, $crawler->filter('[data-testid="subscription-notice"]'));
        self::assertCount(0, $crawler->selectButton('Pause'), 'The page offers no pause for such a plan.');
    }

    public function testTheCancellationIsConfirmedWithTheDateItTakesEffectOn(): void
    {
        $customer = $this->signedInCustomer();
        $subscription = $this->subscriptionOf($customer, ['CommitmentCount' => 3, 'InstallmentsBilled' => 1]);
        $service = $this->getService(AccountSubscriptions::class);
        $announced = $service->cancellationDate($subscription);

        $crawler = $this->client->request('GET', '/account/subscriptions/'.$subscription->getId().'/cancel');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('2028-04-01', $announced->format('Y-m-d'), 'The paid period ends on 2028-02-01 and two committed installments are still due.');
        self::assertStringContainsString('April 1, 2028', $crawler->filter('[data-testid="subscription-cancel-until"]')->text());
    }

    public function testTheDateAnnouncedIsTheOneTheCoreWritesOnCancellation(): void
    {
        $customer = $this->signedInCustomer();
        $subscription = $this->subscriptionOf($customer, ['CommitmentCount' => 3, 'InstallmentsBilled' => 1]);
        $announced = $this->getService(AccountSubscriptions::class)->cancellationDate($subscription);

        $this->client->request('POST', '/account/subscriptions/'.$subscription->getId().'/cancel', ['_token' => $this->token($subscription)]);
        self::assertResponseRedirects();

        $cancelled = $this->reread($subscription);
        self::assertSame('cancelled', $cancelled->getStatus());
        self::assertSame($announced->format('Y-m-d'), $cancelled->getCancelAt('Y-m-d'));
        self::assertSame('customer', $this->lastActorOf($cancelled));
    }

    public function testAnActionWithoutTheTokenIsForbidden(): void
    {
        $customer = $this->signedInCustomer();
        $subscription = $this->subscriptionOf($customer);

        $this->client->request('POST', '/account/subscriptions/'.$subscription->getId().'/cancel', ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('active', $this->reread($subscription)->getStatus());
    }

    public function testACancelledSubscriptionOffersNoMoreActionsAndAPostIsAMessage(): void
    {
        $customer = $this->signedInCustomer();
        $subscription = $this->subscriptionOf($customer, ['Status' => 'ended']);
        $token = $this->token($this->subscriptionOf($customer));

        $this->client->request('POST', '/account/subscriptions/'.$subscription->getId().'/cancel', ['_token' => $token]);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="subscription-notice"]'));
        self::assertCount(0, $crawler->filter('a[href$="/cancel"]'));
    }

    public function testTheCustomerChangesTheMethodToOneOfTheirOwnOfTheSameModule(): void
    {
        $customer = $this->signedInCustomer();
        $module = $this->storedModule();
        $first = $this->methodOf($customer, $module, 'Visa ending 1111');
        $second = $this->methodOf($customer, $module, 'Visa ending 2222');
        $subscription = $this->subscriptionOf($customer, ['CustomerPaymentMethodId' => $first->getId(), 'PaymentModuleId' => $module->getId()]);

        $crawler = $this->client->request('GET', '/account/subscriptions/'.$subscription->getId());
        self::assertCount(2, $crawler->filter('[data-testid="subscription-method"] input[name="payment_method"]'));

        $this->client->request('POST', '/account/subscriptions/'.$subscription->getId().'/payment-method', ['_token' => $this->token($subscription), 'payment_method' => $second->getId()]);

        self::assertResponseRedirects();
        self::assertSame((int) $second->getId(), (int) $this->reread($subscription)->getCustomerPaymentMethodId());
    }

    public function testAMethodOfAnotherCustomerIsRefusedAndNothingChanges(): void
    {
        $customer = $this->signedInCustomer();
        $module = $this->storedModule();
        $mine = $this->methodOf($customer, $module, 'Visa ending 1111');
        $theirs = $this->methodOf($this->factory()->customer($this->factory()->customerTitle()), $module, 'Visa ending 9999');
        $subscription = $this->subscriptionOf($customer, ['CustomerPaymentMethodId' => $mine->getId(), 'PaymentModuleId' => $module->getId()]);

        $this->client->request('POST', '/account/subscriptions/'.$subscription->getId().'/payment-method', ['_token' => $this->token($subscription), 'payment_method' => $theirs->getId()]);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="subscription-notice"]'));
        self::assertSame((int) $mine->getId(), (int) $this->reread($subscription)->getCustomerPaymentMethodId());
    }

    private function factory(): FixtureFactory
    {
        return $this->factory ?? throw new \LogicException('Not set up.');
    }

    private function signedInCustomer(): Customer
    {
        $customer = $this->factory()->customer($this->factory()->customerTitle());
        $this->injector?->setCustomer($customer);

        return $customer;
    }

    private function storedModule(): Module
    {
        return $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
    }

    private function methodOf(Customer $customer, Module $module, string $label): CustomerPaymentMethod
    {
        return $this->storedMethodRow($customer, $module, $label, null, false, $this->getPropelConnection());
    }

    /**
     * @param array<string, mixed> $values by phpName, over an active monthly subscription of one unit
     */
    private function subscriptionOf(Customer $customer, array $values = []): Subscription
    {
        $connection = $this->getPropelConnection();
        $factory = $this->factory();

        if (null === $this->product) {
            $this->product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['baseQuantity' => 10, 'basePrice' => 10.0]);
            $this->plan = (new SubscriptionPlan())
                ->setProductId($this->product->getId())
                ->setPeriodUnit('month')
                ->setPeriodCount(1)
                ->setTrialDays(0)
                ->setPausable(1)
                ->setVisible(1)
                ->setPosition(1);
            $this->plan->setLocale('en_US')->setTitle('Monthly box');
            $this->plan->save($connection);
            (new SubscriptionPlanPrice())
                ->setSubscriptionPlanId($this->plan->getId())
                ->setCurrencyId(Currency::getDefaultCurrency()->getId())
                ->setPrice('9.900000')
                ->setFromDefaultCurrency(0)
                ->save($connection);
        }

        $saleElements = ProductSaleElementsQuery::create()->filterByProductId($this->product->getId())->findOne($connection);
        self::assertNotNull($saleElements);
        self::assertNotNull($this->plan);

        $subscription = (new Subscription())
            ->setRef('SUB'.strtoupper(bin2hex(random_bytes(6))))
            ->setCustomerId($customer->getId())
            ->setSubscriptionPlanId($this->plan->getId())
            ->setProductSaleElementsId($saleElements->getId())
            ->setQuantity(1)
            ->setCurrencyId(Currency::getDefaultCurrency()->getId())
            ->setLangId(1)
            ->setPeriodUnit('month')
            ->setPeriodCount(1)
            ->setPausable(1)
            ->setUnitPrice('9.900000')
            ->setDeliveryModuleId(1)
            ->setPaymentModuleId(1)
            ->setStatus('active')
            ->setBillingAnchorDay(1)
            ->setCurrentPeriodStart(new \DateTime('2028-01-01 10:00:00'))
            ->setCurrentPeriodEnd(new \DateTime('2028-02-01 10:00:00'))
            ->setNextBillingAt(new \DateTime('2028-02-01 10:00:00'))
            ->setInstallmentsBilled(1);

        foreach ($values as $name => $value) {
            $subscription->setByName($name, $value);
        }

        $subscription->save($connection);

        return $subscription;
    }

    private function installment(Subscription $subscription, int $number, string $state): void
    {
        (new SubscriptionOrder())
            ->setSubscriptionId($subscription->getId())
            ->setInstallmentNumber($number)
            ->setState($state)
            ->setAttemptCount(1)
            ->save($this->getPropelConnection());
    }

    private function reread(Subscription $subscription): Subscription
    {
        \Thelia\Model\Map\SubscriptionTableMap::clearInstancePool();

        return SubscriptionQuery::create()->findPk($subscription->getId(), $this->getPropelConnection()) ?? self::fail('The subscription went away.');
    }

    private function lastActorOf(Subscription $subscription): ?string
    {
        $history = \Thelia\Model\SubscriptionHistoryQuery::create()
            ->filterBySubscriptionId($subscription->getId())
            ->orderById('DESC')
            ->findOne($this->getPropelConnection());

        return $history?->getActorType();
    }

    /**
     * The token of the actions, read off the cancellation page of a subscription of the signed-in customer that can be cancelled.
     */
    private function token(Subscription $own): string
    {
        $crawler = $this->client->request('GET', '/account/subscriptions/'.$own->getId().'/cancel');

        return (string) $crawler->filter('input[name="_token"]')->attr('value');
    }
}
