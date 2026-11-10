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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Checkout\Service\ConsentAcceptanceStore;
use Thelia\Domain\Checkout\Service\ConsentProvider;
use Thelia\Model\Cart;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Map\CartTableMap;
use Thelia\Model\Map\CustomerTableMap;
use Thelia\Model\ModuleQuery;
use Thelia\Model\OrderQuery;
use FlexyBundle\EventListener\QuantityAdjustedFlashListener;
use Thelia\Test\IntegrationTestCase;

/**
 * The core brought a line back to how its product is sold and refused the payment once, so that the
 * buyer reads the cart again. The buyer is told what moved by the adjustment of each line, shown on the
 * cart; the refusal itself adds no second, vaguer message among the errors.
 */
final class QuantitiesAdjustedRefusalTest extends IntegrationTestCase
{
    public function testALineBroughtBackToItsRulesSendsTheBuyerToTheCartWithTheAdjustmentAloneAndNoError(): void
    {
        if (!class_exists('Thelia\\Domain\\Checkout\\Exception\\CartQuantitiesAdjustedException')) {
            self::markTestSkipped('The installed core predates the quantity rules.');
        }

        $cart = $this->cartWithALineBelowTheMinimumOfItsProduct();

        $request = Request::create('/checkout/pay');
        $request->setSession($this->session());
        $response = static::$kernel->handle($request, HttpKernelInterface::SUB_REQUEST);

        self::assertTrue($response->isRedirection(), 'The payment is refused with a redirection, got '.$response->getStatusCode());
        self::assertStringEndsWith('/checkout/cart', (string) parse_url((string) $response->headers->get('Location'), \PHP_URL_PATH));
        self::assertNull(OrderQuery::create()->findOneByCartId($cart->getId()), 'No order is written.');
        self::assertCount(1, $this->session()->getFlashBag()->peek(QuantityAdjustedFlashListener::FLASH_KEY), 'The line adjusted is told.');
        self::assertSame([], $this->session()->getFlashBag()->peek('error'), 'No second message beside it.');
    }

    private function cartWithALineBelowTheMinimumOfItsProduct(): Cart
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
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['baseQuantity' => 100, 'basePrice' => 50.0]);
        $product->setVirtual(1)->save($this->getPropelConnection());
        $factory->cartItem($cart, $product, null, ['price' => '50.000000', 'promoPrice' => '50.000000']);
        // The smallest quantity moved up after the line was added: the line of one is now below it.
        $product->getProductSaleElementss()->getFirst()->setMinQuantity('2')->save($this->getPropelConnection());
        $cart
            ->setAddressDeliveryId($factory->cartAddress($address, $country)->getId())
            ->setAddressInvoiceId($factory->cartAddress($address, $country)->getId())
            ->setDeliveryModuleId($deliveryModule->getId())
            ->setPaymentModuleId($paymentModule->getId())
            ->setPostage('0')
            ->setPostageTax('0')
            ->save($this->getPropelConnection());

        CustomerTableMap::clearInstancePool();
        CartTableMap::clearInstancePool();
        $this->getService('services_resetter')->reset();

        $session = $this->session();
        $session->setCustomerUser(CustomerQuery::create()->findPk($customer->getId()));
        $session->setSessionCart($cart);
        $session->setCurrency($factory->currency());

        $answers = [];

        foreach ($this->getService(ConsentProvider::class)->mandatoryConsents() as $consent) {
            $answers[(string) $consent->getCode()] = ['accepted' => true, 'title' => (string) $consent->getCode(), 'description' => ''];
        }

        (new ConsentAcceptanceStore($this->getService(RequestStack::class)))->replace($answers);

        return $cart;
    }

    private function session(): Session
    {
        $session = $this->getService(RequestStack::class)->getMainRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);

        return $session;
    }
}
