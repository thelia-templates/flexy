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

namespace FlexyBundle\Tests\Component\Checkout;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Core\Event\Order\OrderPaymentEvent;
use Thelia\Core\Event\Payment\IsValidPaymentEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Checkout\Service\ConsentProvider;
use Thelia\Domain\Payment\StoredPaymentMethodPayment;
use FlexyBundle\Tests\Support\NonBalanceChargingTestPaymentModule;
use Thelia\Model\Cart;
use Thelia\Model\CartQuery;
use Thelia\Model\ConsentQuery;
use Thelia\Model\Customer;
use Thelia\Model\CustomerPaymentMethod;
use Thelia\Model\Map\CartTableMap;
use Thelia\Model\Map\ConsentTableMap;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderPaymentPartQuery;
use Thelia\Model\OrderQuery;
use Thelia\Module\BalanceChargingPaymentModuleInterface;
use Thelia\Module\BaseModule;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\Flexy\CustomerSessionInjector;
use Thelia\Tests\Support\Payment\BalanceChargingTestPaymentModule;
use Thelia\Tests\Support\Payment\InstantTestPaymentModule;
use Thelia\Tests\Support\Payment\StoredMethodsPaymentModule;
use Thelia\Tests\Support\Payment\UsesInstantPaymentModules;
use Thelia\Tests\Support\Payment\UsesStoredMethodsPaymentModule;

/**
 * The payment step with saved payment methods and with a payment in several parts, from
 * a browser's point of view: the page, the live component behind it, and the placement.
 *
 * What the theme owes the core is that every choice reaches the cart through the core's
 * own entry (`CartFacade::setPaymentModule()`), that the order the core would refuse is
 * never presented as payable, and that a refusal at the placement lands the buyer back on
 * the payment step with a sentence, never on an error page or a blank one.
 */
final class PaymentPartsAndStoredMethodsTest extends WebIntegrationTestCase
{
    use UsesInstantPaymentModules;
    use UsesStoredMethodsPaymentModule;

    private const string COMPONENT = 'Organisms:Payment:Base';

    private FixtureFactory $factory;

    private CustomerSessionInjector $injector;

    /** @var list<array{0: string, 1: callable}> */
    private array $registeredListeners = [];

    /** @var list<int> */
    private array $exhaustedCustomerIds = [];

    /** @var list<int> */
    private array $deactivatedModuleIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Not createFixtureFactory(): it leaves a request on the stack, in front of the ones the client sends.
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->injector = new CustomerSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $this->turnTheConsentsOff();
        $this->listen(TheliaEvents::MODULE_PAYMENT_IS_VALID, static function (IsValidPaymentEvent $event): void {
            $event->setValidModule(true);
            $event->stopPropagation();
        }, 512);

        InstantTestPaymentModule::reset();
        BalanceChargingTestPaymentModule::reset();
        StoredMethodsPaymentModule::reset();
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);

        foreach ($this->registeredListeners as [$eventName, $listener]) {
            $this->getService(EventDispatcherInterface::class)->removeListener($eventName, $listener);
        }

        $this->registeredListeners = [];

        foreach ($this->exhaustedCustomerIds as $customerId) {
            static::getContainer()->get('limiter.stored_method_payment')->create('customer:'.$customerId)->reset();
        }

        $this->exhaustedCustomerIds = [];

        $this->reactivateTheModules();
        $this->uninstallThePaymentModules(static::getContainer());
        $this->uninstallTheStoredMethodsPaymentModule(static::getContainer());

        parent::tearDown();
    }

    public function testAPartThatCoversTheOrderOffersNoPaymentMethod(): void
    {
        $instant = $this->installPaymentModule(InstantTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        // A provider that could charge a balance: it is hidden because there is none to charge.
        $this->installPaymentModule(BalanceChargingTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        InstantTestPaymentModule::$appliedAmount = '99999';
        $cart = $this->readyCart(null);

        $crawler = $this->requestThePaymentStep();

        self::assertCount(0, $crawler->filter('.PaymentCard-content'), 'No provider is offered for an order that is paid.');
        self::assertStringContainsString('Nothing is left to pay', $crawler->text());
        self::assertSame(
            (int) $instant->getId(),
            $this->reread($cart)->getPaymentModuleId(),
            'The means that carries the order is the payment module of the cart, for the step to be settled.',
        );
    }

    public function testAnOrderThePartsCoverIsPlacedWithoutAProviderAndWithoutABlankPage(): void
    {
        $this->installPaymentModule(InstantTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        InstantTestPaymentModule::$appliedAmount = '99999';
        $cart = $this->readyCart(null);

        $this->requestThePaymentStep();
        $this->client->request('GET', '/checkout/pay');

        self::assertResponseRedirects('/checkout/confirm');
        $order = $this->orderOf($cart);
        self::assertSame(1, OrderPaymentPartQuery::create()->filterByOrderId($order->getId())->count(), 'One part pays the order.');
        self::assertNotContains('pay', InstantTestPaymentModule::$calls, 'No provider is asked to charge anything.');
        self::assertContains('settle', InstantTestPaymentModule::$calls);
    }

    public function testTheOrderIsPlacedEvenWhenThePaymentStepWasNeverVisited(): void
    {
        $this->installPaymentModule(InstantTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        InstantTestPaymentModule::$appliedAmount = '99999';
        $cart = $this->readyCart(null);

        $this->client->request('GET', '/checkout/pay');

        self::assertResponseRedirects('/checkout/confirm');
        self::assertSame(1, OrderQuery::create()->filterByCartId($cart->getId())->count());
    }

    public function testAPaymentModuleThatSettlesOnTheSpotWithAnEmptyAnswerDoesNotLeaveABlankPage(): void
    {
        $cart = $this->readyCart($this->moduleNamed('Cheque'));

        // A module that took the payment at once answers with nothing to display.
        $this->listen(TheliaEvents::MODULE_PAY, static function (OrderPaymentEvent $event): void {
            $event->setResponse(new Response(''));
            $event->stopPropagation();
        }, 256);

        $this->client->request('GET', '/checkout/pay');

        self::assertResponseRedirects('/checkout/confirm');
        self::assertSame(1, OrderQuery::create()->filterByCartId($cart->getId())->count());
    }

    public function testAPartRemovedAfterItCoveredTheOrderLeavesTheCartWithoutPaymentModule(): void
    {
        $instant = $this->installPaymentModule(InstantTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        InstantTestPaymentModule::$appliedAmount = '99999';
        $cart = $this->readyCart(null);

        $this->requestThePaymentStep();
        self::assertSame((int) $instant->getId(), $this->reread($cart)->getPaymentModuleId());

        // The module's own answer is the only thing that changes: no listener stands in for it.
        InstantTestPaymentModule::$appliedAmount = '0';
        $crawler = $this->requestThePaymentStep();

        self::assertNull($this->reread($cart)->getPaymentModuleId(), 'An instant means that pays nothing is no longer the payment module of the cart.');
        self::assertNull($this->reread($cart)->getCustomerPaymentMethodId());
        self::assertGreaterThanOrEqual(1, $crawler->filter('.PaymentCard-content')->count(), 'The buyer picks a provider again.');
    }

    public function testAPartLoweredBelowTheOrderLeavesTheCartWithoutPaymentModule(): void
    {
        $this->installPaymentModule(InstantTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        $this->installPaymentModule(BalanceChargingTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        InstantTestPaymentModule::$appliedAmount = '99999';
        $cart = $this->readyCart(null);

        $this->requestThePaymentStep();
        InstantTestPaymentModule::$appliedAmount = '1.00';
        $this->requestThePaymentStep();

        self::assertNull($this->reread($cart)->getPaymentModuleId());
    }

    public function testAProviderChosenBeforeThePartsCoveredTheOrderGivesWayToTheMeansThatPaysIt(): void
    {
        [$cart, , $storedModule, , $default] = $this->cartWithStoredMethods();
        $instant = $this->installPaymentModule(InstantTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());

        $this->chooseTheModule($storedModule);
        self::assertSame($default->getId(), $this->reread($cart)->getCustomerPaymentMethodId());

        InstantTestPaymentModule::$appliedAmount = '99999';
        $this->requestThePaymentStep();

        $cart = $this->reread($cart);
        self::assertSame((int) $instant->getId(), $cart->getPaymentModuleId(), 'The means that pays the order is its module.');
        self::assertNull($cart->getCustomerPaymentMethodId(), 'The method stored through the provider does not follow.');
    }

    public function testAPartThatNoProviderCanChargeTheBalanceOfIsToldToTheBuyer(): void
    {
        $this->deactivateTheModulesThatChargeTheBalance();
        $this->installPaymentModule(InstantTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        $provider = $this->installPaymentModule(NonBalanceChargingTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        InstantTestPaymentModule::$appliedAmount = '1.00';
        $this->readyCart(null);
        // No module that can charge a balance is active: the only provider left cannot.
        $balanceCapable = $this->getService(\FlexyBundle\Service\InstantPaymentParts::class);
        self::assertFalse($balanceCapable->chargesTheBalance((int) $provider->getId()), 'The scenario needs a provider that cannot charge a balance.');

        $crawler = $this->requestThePaymentStep();

        self::assertCount(0, $crawler->filter('.PaymentCard-content'));
        self::assertStringContainsString('cannot be combined with another payment method: remove the part or cover the whole total', $crawler->text());
    }

    public function testThePaymentStepReadsTheCompositionAgainWhenThePartsChange(): void
    {
        $instant = $this->installPaymentModule(InstantTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        $cart = $this->readyCart(null);

        $before = $this->requestThePaymentStep();
        self::assertStringNotContainsString('Nothing is left to pay', $before->text());
        self::assertNull($this->reread($cart)->getPaymentModuleId());

        // The module changed its part in a request of its own; the step is told afterwards.
        InstantTestPaymentModule::$appliedAmount = '99999';
        $component = $before->filter(\sprintf('[data-live-url-value][data-live-name-value="%s"]', self::COMPONENT))->first();
        $after = $this->liveStepOf($component, 'readThePartsAgain', []);

        self::assertStringContainsString('Nothing is left to pay', $after->text());
        self::assertSame((int) $instant->getId(), $this->reread($cart)->getPaymentModuleId());
    }

    public function testAProviderThatCannotChargeTheBalanceIsToldToTheBuyerWhenAnotherProviderCan(): void
    {
        $this->installPaymentModule(InstantTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        $this->installPaymentModule(BalanceChargingTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        $provider = $this->installPaymentModule(NonBalanceChargingTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        InstantTestPaymentModule::$appliedAmount = '1.00';
        $this->readyCart((int) $provider->getId());

        $crawler = $this->requestThePaymentStep();

        self::assertStringContainsString('The payment method you chose cannot be combined with another payment means: choose another one.', $crawler->text());
        self::assertStringNotContainsString('This means cannot be combined', $crawler->text(), 'A provider can charge the balance: the buyer has something to choose.');
    }

    public function testACartWithoutIdIsNeverReadFromTheMemoOfAnotherOne(): void
    {
        $this->installPaymentModule(InstantTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        InstantTestPaymentModule::$appliedAmount = '0';
        $parts = $this->getService(\FlexyBundle\Service\InstantPaymentParts::class);
        $parts->reset();

        InstantTestPaymentModule::$askedAboutCartIds = [];
        $parts->viewOf(new Cart(), null, '');
        $parts->viewOf(new Cart(), null, '');
        self::assertCount(2, InstantTestPaymentModule::$askedAboutCartIds, 'Two carts with no id share no key: each is asked.');

        $saved = $this->readyCart(null);
        InstantTestPaymentModule::$askedAboutCartIds = [];
        $parts->viewOf($saved, null, '');
        $parts->viewOf($saved, null, '');
        self::assertCount(1, InstantTestPaymentModule::$askedAboutCartIds, 'A saved cart is asked once: the memo holds for it.');
    }

    public function testThePaymentStepListensToThePartsChangedEventOfTheModules(): void
    {
        $this->installPaymentModule(InstantTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        $this->readyCart(null);

        $component = $this->requestThePaymentStep()->filter(\sprintf('[data-live-name-value="%s"]', self::COMPONENT))->first();
        self::assertCount(1, $component);

        $listeners = json_decode((string) $component->attr('data-live-listeners-value'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertContains(
            ['action' => 'readThePartsAgain', 'event' => \FlexyBundle\Event\CheckoutEvents::PAYMENT_PARTS_CHANGED],
            $listeners,
            'The event a live component of a module emits (from the browser, to every live component) reaches the step through this declaration.',
        );
    }

    public function testTheModulesAreAskedTheirPartOnlyOncePerCompositionWhenTheStepIsDrawn(): void
    {
        $this->installPaymentModule(InstantTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        $this->installPaymentModule(BalanceChargingTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        InstantTestPaymentModule::$appliedAmount = '1.00';
        $this->readyCart(null);
        InstantTestPaymentModule::$askedAboutCartIds = [];

        $this->requestThePaymentStep();

        // One read for the theme's view and one inside the core's composer, for the one
        // composition the step needs; it was four before the reads were kept.
        self::assertLessThanOrEqual(2, \count(InstantTestPaymentModule::$askedAboutCartIds));
    }

    public function testTheTitleOfThePartsFollowsTheLevelOfTheStepTitle(): void
    {
        $this->installPaymentModule(InstantTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        $this->readyCart(null);

        $crawler = $this->requestThePaymentStep();
        $title = $crawler->filter('#payment-parts-title')->first();

        self::assertCount(1, $title);
        self::assertSame('h2', $title->nodeName(), 'One level under the h1 of the step.');
    }

    public function testAPartialPartLeavesTheBalanceToAProviderThatCanChargeIt(): void
    {
        $this->deactivateTheModulesThatChargeTheBalance();
        $this->installPaymentModule(InstantTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        $this->installPaymentModule(NonBalanceChargingTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        $balanceModule = $this->installPaymentModule(BalanceChargingTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        InstantTestPaymentModule::$appliedAmount = '1.00';
        $cart = $this->readyCart((int) $balanceModule->getId());

        $crawler = $this->requestThePaymentStep();

        self::assertStringContainsString('Left to pay', $crawler->text());
        self::assertCount(
            1,
            $crawler->filter('.PaymentCard-content'),
            'Only the module that charges the balance is offered: the others would be refused at the placement.',
        );

        $this->client->request('GET', '/checkout/pay');

        $order = $this->orderOf($cart);
        self::assertSame(2, OrderPaymentPartQuery::create()->filterByOrderId($order->getId())->count(), 'The part, and the balance.');
        self::assertSame([(int) $order->getId()], BalanceChargingTestPaymentModule::$paidOrderIds);
    }

    public function testAProviderThatCannotChargeABalanceIsRefusedBackToThePaymentStepWithAMessage(): void
    {
        $this->deactivateTheModulesThatChargeTheBalance();
        $this->installPaymentModule(InstantTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        $provider = $this->installPaymentModule(NonBalanceChargingTestPaymentModule::class, static::getContainer(), $this->getPropelConnection());
        InstantTestPaymentModule::$appliedAmount = '1.00';
        $cart = $this->readyCart((int) $provider->getId());

        $crawler = $this->requestThePaymentStep();

        self::assertStringContainsString('cannot be combined with another payment method: remove the part', $crawler->text());

        $this->client->request('GET', '/checkout/pay');

        self::assertResponseRedirects('/checkout/payment');
        self::assertSame(0, OrderQuery::create()->filterByCartId($cart->getId())->count());
    }

    public function testTheDefaultStoredMethodIsPreselectedWhenTheModuleIsChosen(): void
    {
        [$cart, , $module, $other, $default] = $this->cartWithStoredMethods();

        $step = $this->chooseTheModule($module);

        self::assertSame($default->getId(), $this->reread($cart)->getCustomerPaymentMethodId(), 'Written on the cart, through the core.');
        self::assertCount(3, $step->filter('input[data-live-action-param="chooseStoredMethod"]'), 'Two saved methods and the option of a new one.');
        self::assertNotNull($this->radioOf($step, $default->getId())->attr('checked'));
        self::assertNull($this->radioOf($step, $other->getId())->attr('checked'));

        // The reference the provider keeps the method under is never in what the theme draws.
        $page = $this->client->request('GET', '/checkout/payment');
        self::assertStringNotContainsString('pm_secret_', $page->html());
        self::assertStringNotContainsString('pm_secret_', $step->html());

        $this->client->request('GET', '/checkout/pay');

        self::assertSame([(int) $default->getId()], StoredMethodsPaymentModule::$paidWithMethodIds);
    }

    public function testAnotherStoredMethodChosenInTheCardIsWrittenOnTheCart(): void
    {
        [$cart, , $module, $other] = $this->cartWithStoredMethods();

        $this->chooseTheModule($module);
        $step = $this->liveStep('chooseStoredMethod', ['customerPaymentMethodId' => $other->getId()]);

        self::assertSame($other->getId(), $this->reread($cart)->getCustomerPaymentMethodId());
        self::assertNotNull($this->radioOf($step, $other->getId())->attr('checked'));

        $this->client->request('GET', '/checkout/pay');

        self::assertSame([(int) $other->getId()], StoredMethodsPaymentModule::$paidWithMethodIds);
    }

    public function testAClickOnTheCardAlreadyChosenKeepsTheMethodPickedInIt(): void
    {
        [$cart, , $module, $other] = $this->cartWithStoredMethods();

        $this->chooseTheModule($module);
        $this->liveStep('chooseStoredMethod', ['customerPaymentMethodId' => $other->getId()]);
        $this->chooseTheModule($module);

        self::assertSame($other->getId(), $this->reread($cart)->getCustomerPaymentMethodId());
    }

    public function testANewMethodIsChosenByWritingNoMethodOnTheCart(): void
    {
        [$cart, , $module] = $this->cartWithStoredMethods();

        $this->chooseTheModule($module);
        $step = $this->liveStep('chooseStoredMethod', ['customerPaymentMethodId' => 0]);

        self::assertNull($this->reread($cart)->getCustomerPaymentMethodId());
        $newMethod = $this->radioOf($step, null);
        self::assertCount(1, $newMethod);
        self::assertNotNull($newMethod->attr('checked'), 'The option of a new method is the one shown as chosen.');

        $this->client->request('GET', '/checkout/pay');

        self::assertSame([], StoredMethodsPaymentModule::$paidWithMethodIds);
        self::assertContains('pay', StoredMethodsPaymentModule::$calls, 'The provider is asked for a new method.');
    }

    public function testAMethodOfAnotherCustomerIsRefusedByTheCoreAndTheChoiceIsKept(): void
    {
        [$cart, , $module, , $default] = $this->cartWithStoredMethods();
        $stranger = $this->factory->customer($this->factory->customerTitle());
        $theirs = $this->storedMethodRow($stranger, $module, 'Not yours', '2030-12-31', true, $this->getPropelConnection());

        $this->chooseTheModule($module);
        $step = $this->liveStep('chooseStoredMethod', ['customerPaymentMethodId' => $theirs->getId()]);

        self::assertSame($default->getId(), $this->reread($cart)->getCustomerPaymentMethodId());
        self::assertStringContainsString('can no longer be used', $step->text());
    }

    public function testAnOrderOverTheStoredMethodAllowanceGoesBackToThePaymentStepWithAMessage(): void
    {
        [$cart, $customer, $module, , $default] = $this->cartWithStoredMethods();

        $this->chooseTheModule($module);
        $this->spendTheWholeHourlyAllowanceOf($customer, $default);

        $this->client->request('GET', '/checkout/pay');

        self::assertResponseRedirects('/checkout/payment');
        self::assertSame(0, OrderQuery::create()->filterByCartId($cart->getId())->count(), 'The core refused before any order was written.');

        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Too many payments with a saved payment method', (string) $this->client->getResponse()->getContent());
    }

    public function testTheTunnelIsUnchangedForABuyerWithNoSavedMethodAndNoPart(): void
    {
        $cart = $this->readyCart($this->moduleNamed('Cheque'));

        $crawler = $this->requestThePaymentStep();

        self::assertGreaterThanOrEqual(1, $crawler->filter('.PaymentCard-content')->count(), 'The payment methods are listed.');
        self::assertStringNotContainsString('Pay part of your order with', $crawler->text());
        self::assertCount(0, $crawler->filter('input[data-live-action-param="chooseStoredMethod"]'));

        $this->client->request('GET', '/checkout/pay');

        self::assertResponseRedirects('/checkout/confirm');
        self::assertSame(1, OrderQuery::create()->filterByCartId($cart->getId())->count());
        self::assertSame(0, OrderPaymentPartQuery::create()->filterByOrderId($this->orderOf($cart)->getId())->count(), 'One module, no part.');
    }

    /**
     * What the card's radio does: the event of the module chosen, sent to the live component the
     * page rendered, the way the browser sends it.
     */
    private function chooseTheModule(Module $module): Crawler
    {
        return $this->liveStep('selectPaymentModuleId', ['moduleId' => $module->getId()]);
    }

    /**
     * One action of the payment step's live component, read off the page as the Stimulus
     * controller reads it: the url and the props the page rendered, the arguments of the click.
     * Answers the component as it is drawn again.
     *
     * @param array<string, mixed> $arguments
     */
    private function liveStep(string $action, array $arguments): Crawler
    {
        $component = $this->requestThePaymentStep()->filter(\sprintf('[data-live-url-value][data-live-name-value="%s"]', self::COMPONENT))->first();

        return $this->liveStepOf($component, $action, $arguments);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function liveStepOf(Crawler $component, string $action, array $arguments): Crawler
    {
        self::assertCount(1, $component, 'The payment step must render its live component.');

        $this->client->request(
            'POST',
            \sprintf('%s/%s', $component->attr('data-live-url-value'), $action),
            ['data' => json_encode([
                'props' => json_decode((string) $component->attr('data-live-props-value'), true, 512, \JSON_THROW_ON_ERROR),
                'updated' => new \stdClass(),
                'args' => $arguments,
            ], \JSON_THROW_ON_ERROR)],
            [],
            [
                'HTTP_ACCEPT' => 'application/vnd.live-component+html',
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            ],
        );

        self::assertResponseIsSuccessful();

        return new Crawler((string) $this->client->getResponse()->getContent());
    }

    private function radioOf(Crawler $step, ?int $methodId): Crawler
    {
        return $step->filter(\sprintf(
            'input[data-live-action-param="chooseStoredMethod"][data-live-customer-payment-method-id-param="%d"]',
            (int) $methodId,
        ));
    }

    private function requestThePaymentStep(): Crawler
    {
        $crawler = $this->client->request('GET', '/checkout/payment');

        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @return array{0: Cart, 1: Customer, 2: Module, 3: CustomerPaymentMethod, 4: CustomerPaymentMethod}
     *
     * The first method is not the default, the second is: the order they are stored in is
     * not the order the buyer prefers
     */
    private function cartWithStoredMethods(): array
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $cart = $this->readyCart(null);
        $customer = $cart->getCustomer() ?? self::fail('The cart has no customer.');

        $other = $this->storedMethodRow($customer, $module, 'Mastercard ending 1111', '2030-12-31', false, $this->getPropelConnection());
        $default = $this->storedMethodRow($customer, $module, 'Visa ending 4242', '2030-12-31', true, $this->getPropelConnection());
        static::getContainer()->get('limiter.stored_method_payment')->create('customer:'.$customer->getId())->reset();

        return [$cart, $customer, $module, $other, $default];
    }

    /**
     * An account's cart of one virtual product, ready to be paid, in the session of every request.
     */
    private function readyCart(?int $paymentModuleId): Cart
    {
        $connection = $this->getPropelConnection();
        $country = $this->factory->country();
        $customer = $this->factory->customer($this->factory->customerTitle());
        $address = $this->factory->address($customer, $country);
        $cart = $this->factory->cart($customer);
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->factory->currency(), ['baseQuantity' => 100]);
        $product->setVirtual(1)->save($connection);
        $this->factory->cartItem($cart, $product);

        $cart
            ->setAddressDeliveryId($this->factory->cartAddress($address, $country)->getId())
            ->setAddressInvoiceId($this->factory->cartAddress($address, $country)->getId())
            ->setDeliveryModuleId($this->moduleNamed('VirtualProductDelivery'))
            ->setPaymentModuleId($paymentModuleId)
            ->setPostage('0')
            ->setPostageTax('0')
            ->save($connection);

        $this->injector->setCustomer($customer);
        $this->injector->setCart($cart);

        return $cart;
    }

    /**
     * Takes out of the shop, for the length of the test, every active payment module that can
     * charge a balance (the shop's own ones may, from one release to the next): what a scenario
     * says about "no provider can charge it" must not depend on them. Put back by tearDown.
     */
    private function deactivateTheModulesThatChargeTheBalance(): void
    {
        $connection = $this->getPropelConnection();
        $parts = $this->getService(\FlexyBundle\Service\InstantPaymentParts::class);

        $rows = ModuleQuery::create()
            ->filterByActivate(BaseModule::IS_ACTIVATED)
            ->filterByType(BaseModule::PAYMENT_MODULE_TYPE)
            ->find($connection);

        foreach ($rows as $row) {
            try {
                $instance = $row->getPaymentModuleInstance(static::getContainer());
            } catch (\InvalidArgumentException) {
                continue;
            }

            if ($instance instanceof BalanceChargingPaymentModuleInterface) {
                $row->setActivate(BaseModule::IS_NOT_ACTIVATED)->save($connection);
                $this->deactivatedModuleIds[] = (int) $row->getId();
            }
        }

        $parts->reset();
    }

    private function reactivateTheModules(): void
    {
        foreach ($this->deactivatedModuleIds as $moduleId) {
            ModuleQuery::create()->findPk($moduleId, $this->getPropelConnection())?->setActivate(BaseModule::IS_ACTIVATED)->save($this->getPropelConnection());
        }

        $this->deactivatedModuleIds = [];
    }

    private function moduleNamed(string $code): int
    {
        $module = ModuleQuery::create()->findOneByCode($code) ?? self::fail(\sprintf('No %s module: run bin/test-prepare.', $code));

        return (int) $module->getId();
    }

    private function orderOf(Cart $cart): Order
    {
        return OrderQuery::create()->filterByCartId($cart->getId())->findOne($this->getPropelConnection())
            ?? self::fail('No order was placed from the cart.');
    }

    private function reread(Cart $cart): Cart
    {
        CartTableMap::clearInstancePool();
        CartTableMap::clearRelatedInstancePool();

        return CartQuery::create()->findPk($cart->getId(), $this->getPropelConnection()) ?? self::fail('The cart went away.');
    }

    /**
     * Ten payments already went through for this customer within the hour.
     */
    private function spendTheWholeHourlyAllowanceOf(Customer $customer, CustomerPaymentMethod $method): void
    {
        $payment = $this->getService(StoredPaymentMethodPayment::class);
        $order = (new Order())->setCustomerId($customer->getId());
        $this->exhaustedCustomerIds[] = (int) $customer->getId();

        for ($payments = 0; $payments < 10; ++$payments) {
            $payment->pay(new StoredMethodsPaymentModule(), $order, $method);
        }

        StoredMethodsPaymentModule::reset();
    }

    private function turnTheConsentsOff(): void
    {
        ConsentTableMap::clearInstancePool();

        foreach (ConsentQuery::create()->filterByActive(1)->find($this->getPropelConnection()) as $consent) {
            $consent->setActive(0)->save($this->getPropelConnection());
        }

        $this->getService(ConsentProvider::class)->forgetCache();
    }

    private function listen(string $eventName, callable $listener, int $priority): void
    {
        $this->getService(EventDispatcherInterface::class)->addListener($eventName, $listener, $priority);
        $this->registeredListeners[] = [$eventName, $listener];
    }
}
