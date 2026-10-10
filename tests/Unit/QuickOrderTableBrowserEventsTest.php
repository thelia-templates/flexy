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

use FlexyBundle\Components\Organisms\QuickOrderTable\Base as QuickOrderTable;
use FlexyBundle\Event\CheckoutEvents;
use PHPUnit\Framework\TestCase;
use Symfony\UX\LiveComponent\LiveResponder;
use Thelia\Domain\QuickOrder\DTO\QuickOrderLine;
use Thelia\Domain\QuickOrder\DTO\QuickOrderTable as ControlTable;
use Thelia\Domain\QuickOrder\Enum\LineStatus;

/**
 * The lines the quick order table put in the cart are told to the page, one DOM event per line
 * (CheckoutEvents::BROWSER_ADD_PSE): the cart took them, as it does when the product page adds one.
 *
 * The component is built without its constructor (the quick order service is final, the table it
 * answers with is what matters here) and the step that tells the page is called on the table the
 * service answered.
 */
final class QuickOrderTableBrowserEventsTest extends TestCase
{
    public function testEachLineTheCartTookIsToldToThePage(): void
    {
        $responder = new LiveResponder();
        $table = new ControlTable([
            (new QuickOrderLine('REF-A', 2, LineStatus::Resolved, productSaleElementsId: 7, productId: 70))->markAdded(),
            (new QuickOrderLine('REF-B', 1, LineStatus::Resolved, productSaleElementsId: 8, productId: 80))->markAdded(),
        ]);

        $this->tell($table, $responder);

        self::assertSame(
            [
                ['event' => CheckoutEvents::BROWSER_ADD_PSE, 'payload' => ['pse' => 7, 'quantity' => 2]],
                ['event' => CheckoutEvents::BROWSER_ADD_PSE, 'payload' => ['pse' => 8, 'quantity' => 1]],
            ],
            $responder->getBrowserEventsToDispatch(),
        );
    }

    public function testALineTheCartTurnedDownOrOnlyPartlyTookIsNotTold(): void
    {
        $responder = new LiveResponder();
        $table = new ControlTable([
            (new QuickOrderLine('REF-A', 5, LineStatus::Resolved, productSaleElementsId: 7, productId: 70))->refusedByTheCart(2.0),
            new QuickOrderLine('REF-B', 1, LineStatus::Unknown),
        ]);

        $this->tell($table, $responder);

        self::assertSame([], $responder->getBrowserEventsToDispatch());
    }

    private function tell(ControlTable $table, LiveResponder $responder): void
    {
        $component = (new \ReflectionClass(QuickOrderTable::class))->newInstanceWithoutConstructor();
        $component->setLiveResponder($responder);

        (new \ReflectionMethod($component, 'tellThePageWhatWasAdded'))->invoke($component, $table);
    }
}
