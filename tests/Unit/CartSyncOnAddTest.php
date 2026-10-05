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

namespace FlexyBundle\Tests\Unit;

use FlexyBundle\Components\Organisms\Cart\Base as Cart;
use FlexyBundle\Event\CheckoutEvents;
use PHPUnit\Framework\TestCase;
use Symfony\UX\LiveComponent\Attribute\LiveListener;

/**
 * A cart component rendered beside the product sheet (a cart panel) has to follow an item added from the sheet, which
 * the sheet announces with CheckoutEvents::ADD_ITEM_EVENT; the cross-selling keeps its own event.
 */
final class CartSyncOnAddTest extends TestCase
{
    public function testTheCartIsRefreshedWhenAnItemIsAddedElsewhereOnThePage(): void
    {
        self::assertContains(CheckoutEvents::ADD_ITEM_EVENT, $this->eventsOfSync());
    }

    public function testTheCrossSellingStillRefreshesIt(): void
    {
        self::assertContains('cross_selling_add_to_cart', $this->eventsOfSync());
    }

    /**
     * @return list<string>
     */
    private function eventsOfSync(): array
    {
        $attributes = (new \ReflectionMethod(Cart::class, 'sync'))->getAttributes(LiveListener::class);

        return array_map(static fn (\ReflectionAttribute $attribute): string => $attribute->newInstance()->getEventName(), $attributes);
    }
}
