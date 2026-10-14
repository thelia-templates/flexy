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

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Checkout\Service\ConsentAcceptanceStore;
use Thelia\Domain\Checkout\Service\ConsentProvider;
use Thelia\Model\Cart;
use Thelia\Model\ModuleQuery;
use Thelia\Model\OrderQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * An order paid by a module that answers without a gateway (a cheque, a bank transfer) is
 * done once it is placed: the buyer leaves with an empty cart.
 *
 * The core keeps the cart of an order until that order is paid, so that a declined card
 * leaves the buyer the cart they filled. A cheque or a transfer is paid days later, and
 * until then the cart stayed the session cart: the header still counted its lines, and the
 * next checkout went on from it and cancelled the order waiting for its money.
 *
 * Goes through the route, `/checkout/pay`, and follows the redirection a browser follows.
 * Boots the host kernel on the test database `bin/test-prepare` creates.
 */
final class OrderWithoutGatewayEmptiesTheCartTest extends IntegrationTestCase
{
    private const int MAX_REDIRECTIONS = 3;

    /** @var list<string> */
    private array $visited = [];

    public function testAnOrderPaidByChequeLeavesTheBuyerAnEmptyCart(): void
    {
        $cart = $this->cartReadyToOrder('Cheque');

        $response = $this->browse('/checkout/pay');

        $visited = implode(' -> ', $this->visited);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), 'The confirmation page is answered, after '.$visited);
        self::assertNotNull(OrderQuery::create()->findOneByCartId($cart->getId()), 'The order is placed, after '.$visited);

        $sessionCart = $this->session()->getSessionCart($this->getService(EventDispatcherInterface::class));

        self::assertNotSame($cart->getId(), $sessionCart->getId(), 'The cart of the order is no longer the session cart.');
        self::assertCount(0, $sessionCart->getCartItems(), 'The session cart is a new, empty one.');
    }

    public function testReloadingThePaymentPlacesNoSecondOrder(): void
    {
        $cart = $this->cartReadyToOrder('Cheque');
        $customerId = $cart->getCustomerId();

        $this->browse('/checkout/pay');
        self::assertSame(1, OrderQuery::create()->filterByCustomerId($customerId)->count(), 'The first visit places one order.');

        // The placement uses up the consents the buyer accepted; a reload that carries them
        // again is the one that reaches the payment.
        $this->acceptTheMandatoryConsents();
        $this->browse('/checkout/pay');
        $this->browse('/checkout/confirm');

        self::assertSame(
            1,
            OrderQuery::create()->filterByCustomerId($customerId)->count(),
            'No second order after a reload, after '.implode(' -> ', $this->visited),
        );
    }

    private function cartReadyToOrder(string $paymentModuleCode): Cart
    {
        $factory = $this->createFixtureFactory();
        $country = $factory->country();
        $customer = $factory->customer($factory->customerTitle());

        // A product to download: the carrier that goes with it ships everywhere, and it is
        // not what this is about.
        $deliveryModule = ModuleQuery::create()->findOneByCode('VirtualProductDelivery')
            ?? self::fail('No VirtualProductDelivery module: run bin/test-prepare.');
        $paymentModule = ModuleQuery::create()->findOneByCode($paymentModuleCode)
            ?? self::fail(\sprintf('No %s module: run bin/test-prepare.', $paymentModuleCode));

        $address = $factory->address($customer, $country);
        $cart = $factory->cart($customer);
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['baseQuantity' => 100]);
        $product->setVirtual(1)->save($this->getPropelConnection());
        $factory->cartItem($cart, $product);
        $cart
            ->setAddressDeliveryId($factory->cartAddress($address, $country)->getId())
            ->setAddressInvoiceId($factory->cartAddress($address, $country)->getId())
            ->setDeliveryModuleId($deliveryModule->getId())
            ->setPaymentModuleId($paymentModule->getId())
            ->setPostage('0')
            ->setPostageTax('0')
            ->save($this->getPropelConnection());

        $session = $this->session();
        $session->setCustomerUser($customer);
        $session->setSessionCart($cart);
        $session->setCurrency($factory->currency());

        $this->acceptTheMandatoryConsents();

        return $cart;
    }

    private function acceptTheMandatoryConsents(): void
    {
        $answers = [];

        foreach ($this->getService(ConsentProvider::class)->mandatoryConsents() as $consent) {
            $answers[(string) $consent->getCode()] = ['accepted' => true, 'title' => (string) $consent->getCode(), 'description' => ''];
        }

        (new ConsentAcceptanceStore($this->getService(RequestStack::class)))->replace($answers);
    }

    /**
     * A GET with this test's session, the redirections followed as a browser follows them.
     */
    private function browse(string $path): Response
    {
        $start = $path;
        for ($redirection = 0; $redirection <= self::MAX_REDIRECTIONS; ++$redirection) {
            $this->visited[] = $path;
            $request = Request::create($path);
            $request->setSession($this->session());

            $response = static::$kernel->handle($request, HttpKernelInterface::SUB_REQUEST);

            if (!$response->isRedirection()) {
                return $response;
            }

            $path = (string) parse_url((string) $response->headers->get('Location'), \PHP_URL_PATH);
        }

        self::fail(\sprintf('More than %d redirections from %s.', self::MAX_REDIRECTIONS, $start));
    }

    private function session(): Session
    {
        $session = $this->getService(RequestStack::class)->getMainRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);

        return $session;
    }
}
