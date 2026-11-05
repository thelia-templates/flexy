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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Thelia\Domain\Checkout\CheckoutFacade;
use Thelia\Model\ConfigQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The buyer who walks into the checkout holds the stock of the lines of their cart (when the shop reserves its stock), and a
 * line another cart holds sends them back to the cart with the words of the core. The cart page and a page fetched ahead of a
 * click hold nothing, and a shop that does not reserve is left as it was.
 */
final class CheckoutStockHoldTest extends IntegrationTestCase
{
    use HoldsStock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startHoldingStock();
    }

    protected function tearDown(): void
    {
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
    public function testEnteringTheCheckoutWhileAnotherCartHoldsTheLastUnitSendsTheBuyerBackToTheCart(string $step): void
    {
        $product = $this->product(1);
        $this->getService(CheckoutFacade::class)->holdStock($this->cartWith($product)[0]);
        $buyer = $this->customer();
        [$cart, $line] = $this->inTheSession($this->cartWith($product, $buyer), $buyer);
        $this->readyToOrder($cart, $buyer);

        $response = $this->visit($step);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertStringEndsWith('/checkout/cart', (string) $response->headers->get('Location'));
        self::assertStringContainsString('is held in another cart', implode(' ', $this->session()->getFlashBag()->peek('error')));
        self::assertNull($this->reservedUntilOf($line), 'nothing is held for the buyer who was refused');
    }

    /**
     * A step the buyer is sent away from, because what leads to it is not done, holds nothing: the hold follows the screen the
     * buyer is served, not the address they typed.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('stepsBeyondACartThatIsNotReady')]
    public function testAStepTheBuyerIsSentAwayFromHoldsNothing(string $step): void
    {
        [, $line] = $this->inTheSession($this->cartWith($this->product(5)));

        $response = $this->visit($step);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertNull($this->reservedUntilOf($line), 'nothing is held on a step that is not served');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stepsBeyondACartThatIsNotReady(): iterable
    {
        yield 'payment' => ['/checkout/payment'];
        yield 'a step of a module' => ['/checkout/step/a_module_step'];
    }

    public function testEnteringTheCheckoutHoldsTheStockOfTheCart(): void
    {
        [, $line] = $this->inTheSession($this->cartWith($this->product(5)));

        $this->visit('/checkout/delivery');

        self::assertNotNull($this->reservedUntilOf($line));
    }

    public function testAShopThatDoesNotReserveItsStockHoldsNothingAndRefusesNothing(): void
    {
        $this->reserve(false);
        $product = $this->product(1);
        $this->cartWith($product);
        [, $line] = $this->inTheSession($this->cartWith($product));

        $response = $this->visit('/checkout/delivery');

        self::assertNull($this->reservedUntilOf($line));
        self::assertSame([], $this->session()->getFlashBag()->peek('error'));
        self::assertStringEndsNotWith('/checkout/cart', (string) $response->headers->get('Location'), 'the line the other cart has is not a refusal when nothing is reserved');
    }

    public function testTheCartPageHoldsNothing(): void
    {
        [, $line] = $this->inTheSession($this->cartWith($this->product(5)));

        $response = $this->visit('/checkout/cart');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNull($this->reservedUntilOf($line));
    }

    public function testAPageFetchedAheadOfAClickHoldsNothing(): void
    {
        [, $line] = $this->inTheSession($this->cartWith($this->product(5)));

        // The controller reads the main request, which is the one of the browser.
        static::getContainer()->get('request_stack')->getMainRequest()->headers->set('Sec-Purpose', 'prefetch');

        $this->visit('/checkout/delivery');

        self::assertNull($this->reservedUntilOf($line));
    }

    public function testOnTheOnePageCheckoutARefusalIsToldWithoutSendingTheBuyerInCircles(): void
    {
        ConfigQuery::write('checkout_display_mode', 'one_page');
        $product = $this->product(1);
        $this->getService(CheckoutFacade::class)->holdStock($this->cartWith($product)[0]);
        $this->inTheSession($this->cartWith($product));

        $response = $this->visit('/checkout/cart');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), 'the cart page is the checkout: no redirection to itself');
        self::assertStringContainsString('is held in another cart', (string) $response->getContent(), 'the cart page tells it');
    }

    private function visit(string $path): Response
    {
        $request = Request::create($path);
        $request->setSession($this->session());

        return static::$kernel->handle($request, HttpKernelInterface::SUB_REQUEST);
    }
}
