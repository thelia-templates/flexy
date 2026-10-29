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

use FlexyBundle\EventListener\QuantityAdjustedFlashListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\Event\Cart\CartItemQuantityAdjustedEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Catalog\Quantity\QuantityAdjustment;
use Thelia\Domain\Catalog\Quantity\QuantityDecision;
use Thelia\Domain\Catalog\Quantity\QuantityRule;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\CartItem;
use Thelia\Model\Product;

/**
 * A line the core brought back to the rules of its product is left in the flash bag with the
 * product's title and the core's sentence, for the cart page to tell; a core without the event
 * gets no subscription at all.
 */
final class QuantityAdjustedFlashListenerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(CartItemQuantityAdjustedEvent::class)) {
            self::markTestSkipped('This core does not adjust quantities.');
        }

        try {
            class_exists(CartItem::class);
        } catch (\Error) {
            self::markTestSkipped('The generated Propel models are not autoloaded.');
        }
    }

    public function testItListensToTheAdjustmentOfALine(): void
    {
        self::assertSame(
            [TheliaEvents::CART_ITEM_QUANTITY_ADJUSTED => 'remember'],
            QuantityAdjustedFlashListener::getSubscribedEvents(),
        );
    }

    public function testTheLineAndTheSentenceOfTheCoreAreLeftForTheCartPage(): void
    {
        [$listener, $session] = $this->listener();
        $decision = new QuantityDecision(7.0, 6.0, QuantityAdjustment::OffStep, new QuantityRule(2.0, 6.0));

        $listener->remember(new CartItemQuantityAdjustedEvent($this->line('Wine, case of six'), $decision));

        self::assertSame(
            [['product' => 'Wine, case of six', 'message' => $decision->message()]],
            $session->getFlashBag()->get(QuantityAdjustedFlashListener::FLASH_KEY),
        );
    }

    public function testALineKeptAsItWasTellsNothing(): void
    {
        [$listener, $session] = $this->listener();

        $listener->remember(new CartItemQuantityAdjustedEvent($this->line('Coffee'), new QuantityDecision(6.0, 6.0, null, new QuantityRule())));

        self::assertSame([], $session->getFlashBag()->get(QuantityAdjustedFlashListener::FLASH_KEY));
    }

    /**
     * @return array{QuantityAdjustedFlashListener, Session}
     */
    private function listener(): array
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $requests = new RequestStack();
        $requests->push($request);
        $languages = self::createStub(LangService::class);
        $languages->method('getLocale')->willReturn('en_US');

        return [new QuantityAdjustedFlashListener($requests, $languages), $session];
    }

    private function line(string $title): CartItem
    {
        $product = self::createStub(Product::class);
        $product->method('getTitle')->willReturn($title);
        $line = self::createStub(CartItem::class);
        $line->method('getProduct')->willReturn($product);

        return $line;
    }
}
