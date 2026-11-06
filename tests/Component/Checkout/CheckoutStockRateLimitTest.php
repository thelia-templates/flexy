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

use FlexyBundle\Tests\Support\HoldsStock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Thelia\Domain\Checkout\Service\ConsentAcceptanceStore;
use Thelia\Domain\Checkout\Service\ConsentProvider;
use Thelia\Model\Cart;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\OrderQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * A cart that holds its stock more often than a buyer does (over the budget of holds of the core) is told to wait, on every
 * step of the checkout that holds the stock: the page, the live components of the one-page checkout, the order button. It is
 * not a shortage (no word of another cart or of a product that ran out), and never a server error.
 *
 * Boots the host kernel on the test database `bin/test-prepare` creates.
 */
final class CheckoutStockRateLimitTest extends IntegrationTestCase
{
    use HoldsStock;

    private ?Cart $limitedCart = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startHoldingStock();
    }

    protected function tearDown(): void
    {
        $this->limitedCart && $this->perCartLimiter()->create((string) $this->limitedCart->getId())->reset();
        $this->stopHoldingStock();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stepsOfTheCheckout(): iterable
    {
        yield 'delivery' => ['/checkout/delivery'];
        yield 'payment' => ['/checkout/payment'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stepsOfTheCheckout')]
    public function testAStepOverTheBudgetOfHoldsIsShownWithTheDelayToWaitAndNoServerError(string $step): void
    {
        [$buyer, $cart] = $this->buyerOverTheBudget();
        $this->readyToOrder($cart, $buyer);

        $response = $this->visit($step);

        self::assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertNotSame(Response::HTTP_FOUND, $response->getStatusCode(), 'the buyer is not sent away from a step for a delay to wait');
        // The page shows the flash it was served with: it is read from the page, not from the session.
        $page = (string) $response->getContent();
        self::assertStringContainsString('Too many attempts to hold your products', $page);
        self::assertMatchesRegularExpression('/in \d+ (second|minute)/', $page, 'the delay to wait is told');
        self::assertStringNotContainsString('held in another cart', $page);
        self::assertStringNotContainsString('ran out', $page);
    }

    public function testThePayButtonOverTheBudgetSendsTheBuyerBackToThePaymentStepWithTheDelayAndWritesNoOrder(): void
    {
        [$buyer, $cart] = $this->buyerOverTheBudget();
        $this->readyToOrder($cart, $buyer);
        $this->acceptTheMandatoryConsents();

        $response = $this->visit('/checkout/pay');

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertStringEndsWith('/checkout/payment', (string) $response->headers->get('Location'));
        $flashes = $this->session()->getFlashBag()->peek('error');
        self::assertCount(1, $flashes);
        self::assertStringContainsString('Too many attempts to hold your products', $flashes[0]);
        self::assertStringNotContainsString('held in another cart', $flashes[0]);
        self::assertSame(0, OrderQuery::create()->filterByCartId($cart->getId())->count(), 'no order is written');
    }

    public function testTheDelayIsToldOnceWhenThePayButtonLandsOnAStepThatSaysItToo(): void
    {
        [$buyer, $cart] = $this->buyerOverTheBudget();
        $this->readyToOrder($cart, $buyer);
        $this->acceptTheMandatoryConsents();
        $this->visit('/checkout/pay');

        $page = (string) $this->visit('/checkout/payment')->getContent();

        self::assertSame(1, substr_count($page, 'Too many attempts to hold your products'));
    }

    public function testTheOnePageCheckoutOverTheBudgetShowsTheDelayWithNoServerError(): void
    {
        ConfigQuery::write('checkout_display_mode', 'one_page');
        [$buyer, $cart] = $this->buyerOverTheBudget();
        $this->readyToOrder($cart, $buyer);

        $response = $this->visit('/checkout/cart');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), 'the cart page is the whole tunnel: no redirection to itself');
        $page = (string) $response->getContent();
        self::assertSame(1, substr_count($page, 'Too many attempts to hold your products'));
        self::assertMatchesRegularExpression('/in \d+ (second|minute)/', $page, 'the delay to wait is told');
        self::assertStringNotContainsString('held in another cart', $page);
        self::assertStringNotContainsString('ran out', $page);
    }

    public function testTheSameRefusalWithAnotherDelayIsNotFlashedTwice(): void
    {
        [$buyer, $cart] = $this->buyerOverTheBudget();
        $this->readyToOrder($cart, $buyer);
        // What the pay button flashed a moment ago: the delay left is one second more than the step computes now.
        $this->session()->getFlashBag()->add('error', 'Too many attempts to hold your products. Please try again in 3599 minutes.');

        $page = (string) $this->visit('/checkout/payment')->getContent();

        self::assertSame(1, substr_count($page, 'Too many attempts to hold your products'), 'told by its message, not by the seconds left');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function choicesOfTheBuyer(): iterable
    {
        yield 'delivery address' => ['Organisms:Delivery:Base'];
        yield 'billing address' => ['Organisms:Invoice:Base'];
        yield 'payment module' => ['Organisms:Payment:Base'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('choicesOfTheBuyer')]
    public function testAChoiceOverTheBudgetIsToldInTheComponentWithTheDelay(string $componentName): void
    {
        [$buyer] = $this->buyerOverTheBudget();
        $address = (int) $this->fixtures()->address($buyer, $this->fixtures()->country())->getId();

        $factory = $this->getService('ux.twig_component.component_factory');
        $component = $factory->get($componentName);
        $component->setLiveResponder(new \Symfony\UX\LiveComponent\LiveResponder());

        match ($componentName) {
            'Organisms:Delivery:Base' => $component->selectDeliveryAddress($address),
            'Organisms:Invoice:Base' => $component->selectInvoiceAddress($address),
            default => $component->selectPaymentModuleId((int) \Thelia\Model\ModuleQuery::create()->findOneByCode('Cheque')?->getId()),
        };

        self::assertNotNull($component->stockRefusal);
        self::assertStringContainsString('Too many attempts to hold your products', $component->stockRefusal);
        self::assertStringNotContainsString('held in another cart', $component->stockRefusal);
    }

    /**
     * @return array{0: Customer, 1: Cart}
     */
    private function buyerOverTheBudget(): array
    {
        $buyer = $this->customer();
        [$cart] = $this->inTheSession($this->cartWith($this->product(5), $buyer), $buyer);
        $this->limitedCart = $cart;

        $limiter = $this->perCartLimiter()->create((string) $cart->getId());
        $limiter->reset();
        // The budget of an hour, spent: the next hold of this cart is over it.
        $limiter->consume(60);

        return [$buyer, $cart];
    }

    private function perCartLimiter(): RateLimiterFactoryInterface
    {
        $factory = $this->getService('limiter.stock_reservation_per_cart');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);

        return $factory;
    }

    private function visit(string $path): Response
    {
        $request = Request::create($path);
        $request->setSession($this->session());

        return static::$kernel->handle($request, HttpKernelInterface::SUB_REQUEST);
    }

    private function acceptTheMandatoryConsents(): void
    {
        $answers = [];

        foreach ($this->getService(ConsentProvider::class)->mandatoryConsents() as $consent) {
            $answers[(string) $consent->getCode()] = ['accepted' => true, 'title' => (string) $consent->getCode(), 'description' => ''];
        }

        (new ConsentAcceptanceStore($this->getService(RequestStack::class)))->replace($answers);
    }
}
