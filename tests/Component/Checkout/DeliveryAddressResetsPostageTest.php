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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\LiveResponder;
use Symfony\UX\TwigComponent\ComponentFactory;
use Thelia\Core\Event\Cart\CartCheckoutEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\CartQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * Choosing another delivery address takes the carrier off the cart, and the postage that carrier had priced with it:
 * the summary reads that column, and a total still counting a delivery nobody chose is a price the buyer does not owe.
 *
 * Boots the host kernel on the test database `bin/test-prepare` creates.
 */
final class DeliveryAddressResetsPostageTest extends IntegrationTestCase
{
    public function testChoosingAnotherAddressTakesTheCarrierAndItsPostageOffTheCart(): void
    {
        $factory = $this->createFixtureFactory();
        $country = $factory->country();
        $customer = $factory->customer($factory->customerTitle());
        $address = $factory->address($customer, $country);
        $carrier = ModuleQuery::create()->findOneByCode('VirtualProductDelivery')
            ?? self::fail('No VirtualProductDelivery module: run bin/test-prepare.');

        // A carrier that prices the cart it is chosen on, for the address the cart has: what the delivery modules do when
        // the cart is set an address or a carrier. The core runs it on the old carrier for the new address.
        $this->getService(EventDispatcherInterface::class)->addListener(
            TheliaEvents::CART_SET_POSTAGE,
            static function (CartCheckoutEvent $event): void {
                if (null !== $event->getCart()->getDeliveryModuleId()) {
                    $event->getCart()->setPostage('8.95')->setPostageTax('0')->save();
                }
            },
            -255,
        );

        $cart = $factory->cart($customer);
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['baseQuantity' => 10]);
        $product->setVirtual(1)->save($this->getPropelConnection());
        $factory->cartItem($cart, $product);
        $cart
            ->setDeliveryModuleId($carrier->getId())
            ->setPostage('8.95')
            ->setPostageTax('0')
            ->save($this->getPropelConnection());

        $session = $this->getService(RequestStack::class)->getMainRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);
        $session->setCustomerUser($customer);
        $session->setSessionCart($cart);

        $delivery = $this->getService('ux.twig_component.component_factory');
        self::assertInstanceOf(ComponentFactory::class, $delivery);
        $component = $delivery->get('Organisms:Delivery:Base');
        $component->setLiveResponder(new LiveResponder());

        $component->selectDeliveryAddress($address->getId());

        $reloaded = CartQuery::create()->findPk($cart->getId());
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getDeliveryModuleId(), 'the carrier is taken off');
        self::assertSame(0.0, $reloaded->getTaxedPostage(), 'and the postage it was priced with');
    }
}
