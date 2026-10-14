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
namespace FlexyBundle\Tests\Unit\Service;

use FlexyBundle\Service\PlacedOrderMemory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class PlacedOrderMemoryTest extends TestCase
{
    public function testTheOrderIsTheOneTheSessionPlaced(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('flexy.placed_order_id', 42);

        self::assertSame(42, $this->memory($session)->placedOrderId());
    }

    public function testThereIsNoOrderBeforeAPlacementNorAfterItIsForgotten(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $memory = $this->memory($session);

        self::assertNull($memory->placedOrderId());

        $session->set('flexy.placed_order_id', 42);
        $memory->forget();

        self::assertNull($memory->placedOrderId());
    }

    public function testThereIsNoOrderWithoutASession(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/checkout/confirm'));

        self::assertNull((new PlacedOrderMemory($requestStack))->placedOrderId());
    }

    private function memory(Session $session): PlacedOrderMemory
    {
        $request = Request::create('/checkout/confirm');
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new PlacedOrderMemory($requestStack);
    }
}
