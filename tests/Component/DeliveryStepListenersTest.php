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

namespace FlexyBundle\Tests\Component;

use FlexyBundle\Components\Organisms\Delivery\Base as DeliveryStep;
use FlexyBundle\Event\CheckoutEvents;
use PHPUnit\Framework\TestCase;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;

/**
 * Each click of the delivery step reaches it as an event: a listener moved off its method,
 * by a method written between the attribute and the one it decorates, is a click that does
 * nothing, and no page test would say so.
 */
final class DeliveryStepListenersTest extends TestCase
{
    public function testEachEventOfTheStepReachesTheMethodThatHandlesIt(): void
    {
        $expected = [
            ['action' => 'resetAddressForm', 'event' => CheckoutEvents::ADD_NEW_DELIVERY_ADDRESS],
            ['action' => 'resetAddressForm', 'event' => 'cancelAddressForm'],
            ['action' => 'setEditingAddress', 'event' => CheckoutEvents::EDIT_DELIVERY_ADDRESS],
            ['action' => 'deleteAddress', 'event' => CheckoutEvents::DELETE_DELIVERY_ADDRESS],
            ['action' => 'selectDeliveryModuleOption', 'event' => CheckoutEvents::SET_DELIVERY_MODULE_OPTION],
            ['action' => 'selectDeliveryAddress', 'event' => CheckoutEvents::SET_DELIVERY_ORDER_ADDRESS_ID],
            ['action' => 'forgetTheDeliveryDateNotice', 'event' => CheckoutEvents::DELIVERY_DATE_CHOSEN],
            ['action' => 'selectInvoiceAddress', 'event' => CheckoutEvents::SET_INVOICE_ORDER_ADDRESS_ID],
        ];

        $listeners = AsLiveComponent::liveListeners(DeliveryStep::class);

        self::assertEqualsCanonicalizing($expected, $listeners);
    }
}
