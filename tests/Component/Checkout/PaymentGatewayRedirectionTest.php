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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Thelia\Core\Event\Order\OrderPaymentEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Checkout\Service\ConsentAcceptanceStore;
use Thelia\Domain\Checkout\Service\ConsentProvider;
use Thelia\Model\ModuleQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * A payment module that answers the placement with a redirection sends the buyer there.
 *
 * Hosted payment pages are reached that way: the module creates the payment at the
 * provider and redirects to the page the provider gives back. Only a 200 used to be
 * handed to the browser, so such a buyer landed on the confirmation page of an order
 * nobody had paid.
 */
final class PaymentGatewayRedirectionTest extends IntegrationTestCase
{
    private const string GATEWAY_URL = 'https://gateway.example.com/pay/42';

    public function testTheBuyerIsSentToTheGatewayTheModuleRedirectsTo(): void
    {
        $this->cartReadyToOrder();

        $dispatcher = $this->getService(EventDispatcherInterface::class);
        $redirectToTheGateway = static function (OrderPaymentEvent $event): void {
            $event->setResponse(new RedirectResponse(self::GATEWAY_URL));
            $event->stopPropagation();
        };

        // Above the core listener that calls the module's pay(): it stands in for a module
        // whose pay() answers with a redirection.
        $dispatcher->addListener(TheliaEvents::MODULE_PAY, $redirectToTheGateway, 256);

        try {
            $request = Request::create('/checkout/pay');
            $request->setSession($this->session());
            $response = static::$kernel->handle($request, HttpKernelInterface::SUB_REQUEST);
        } finally {
            $dispatcher->removeListener(TheliaEvents::MODULE_PAY, $redirectToTheGateway);
        }

        self::assertTrue($response->isRedirection(), 'The placement answers with a redirection.');
        self::assertSame(self::GATEWAY_URL, $response->headers->get('Location'));
    }

    private function cartReadyToOrder(): void
    {
        $factory = $this->createFixtureFactory();
        $country = $factory->country();
        $customer = $factory->customer($factory->customerTitle());

        $deliveryModule = ModuleQuery::create()->findOneByCode('VirtualProductDelivery')
            ?? self::fail('No VirtualProductDelivery module: run bin/test-prepare.');
        $paymentModule = ModuleQuery::create()->findOneByCode('Cheque')
            ?? self::fail('No Cheque module: run bin/test-prepare.');

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

        $answers = [];

        foreach ($this->getService(ConsentProvider::class)->mandatoryConsents() as $consent) {
            $answers[(string) $consent->getCode()] = ['accepted' => true, 'title' => (string) $consent->getCode(), 'description' => ''];
        }

        (new ConsentAcceptanceStore($this->getService(RequestStack::class)))->replace($answers);
    }

    private function session(): Session
    {
        $session = $this->getService(RequestStack::class)->getMainRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);

        return $session;
    }
}
