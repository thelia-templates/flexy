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
use Thelia\Domain\Checkout\CheckoutFacade;
use Thelia\Domain\Checkout\Service\ConsentAcceptanceStore;
use Thelia\Domain\Checkout\Service\ConsentProvider;
use Thelia\Model\OrderQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The buyer who clicks "Order" while another cart holds the last unit of a line is sent back to the cart, with a sentence in
 * the language of the page, and no order is written.
 *
 * Goes through the route, `/checkout/pay`. Boots the host kernel on the test database `bin/test-prepare` creates.
 */
final class CheckoutPayStockRefusalTest extends IntegrationTestCase
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

    public function testPayingWhileAnotherCartHoldsTheLastUnitSendsTheBuyerBackToTheCartWithAMessage(): void
    {
        $product = $this->product(1);
        $this->getService(CheckoutFacade::class)->holdStock($this->cartWith($product)[0]);
        $buyer = $this->customer();
        [$cart] = $this->inTheSession($this->cartWith($product, $buyer), $buyer);
        $this->readyToOrder($cart, $buyer);
        $this->acceptTheMandatoryConsents();

        $request = Request::create('/checkout/pay');
        $request->setSession($this->session());
        $response = static::$kernel->handle($request, HttpKernelInterface::SUB_REQUEST);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertStringEndsWith('/checkout/cart', (string) $response->headers->get('Location'));
        self::assertStringContainsString('is held in another cart', implode(' ', $this->session()->getFlashBag()->peek('error')));
        self::assertSame(0, OrderQuery::create()->filterByCartId($cart->getId())->count(), 'no order is written');
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
