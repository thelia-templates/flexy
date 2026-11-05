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
use Propel\Runtime\Propel;
use Symfony\UX\LiveComponent\LiveResponder;
use Symfony\UX\TwigComponent\ComponentAttributes;
use Symfony\UX\TwigComponent\ComponentFactory;
use Thelia\Domain\Checkout\CheckoutFacade;
use Thelia\Model\Cart;
use Thelia\Model\CartItem;
use Thelia\Model\Customer;
use Thelia\Model\ModuleQuery;
use Thelia\Test\IntegrationTestCase;
use Twig\Runtime\EscaperRuntime;

/**
 * On the one-page checkout the delivery, the billing address and the payment are chosen in live components, which write the
 * cart through the cart facade. Each choice holds the stock of the cart again (the hold runs out in minutes), and a line
 * another cart has taken in the meantime is told in the component, not answered with a server error.
 *
 * Boots the host kernel on the test database `bin/test-prepare` creates.
 */
final class OnePageCheckoutStockHoldTest extends IntegrationTestCase
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function choicesOfTheBuyer(): iterable
    {
        yield 'delivery address' => ['Organisms:Delivery:Base'];
        yield 'billing address' => ['Organisms:Invoice:Base'];
        yield 'payment module' => ['Organisms:Payment:Base'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('choicesOfTheBuyer')]
    public function testAChoiceHoldsTheStockOfTheCartAgain(string $componentName): void
    {
        [$buyer, $line] = $this->buyerWithALineOf($this->product(5));
        $this->getService(CheckoutFacade::class)->holdStock($line->getCart());
        // The hold of the buyer is about to run out.
        $this->setReservedUntil($line, '1 MINUTE');
        $before = $this->reservedUntilOf($line);

        $component = $this->choose($componentName, $buyer);

        self::assertNull($component->stockRefusal);
        self::assertGreaterThan($before, $this->reservedUntilOf($line), 'the hold is extended by the choice');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('choicesOfTheBuyer')]
    public function testAChoiceWhileAnotherCartHoldsTheLastUnitIsRefusedInTheComponent(string $componentName): void
    {
        $product = $this->product(1);
        $this->getService(CheckoutFacade::class)->holdStock($this->cartWith($product)[0]);
        [$buyer] = $this->buyerWithALineOf($product);

        $component = $this->choose($componentName, $buyer);

        self::assertNotNull($component->stockRefusal, 'the component tells the refusal');
        self::assertStringContainsString('is held in another cart', $component->stockRefusal);
    }

    public function testTheRefusalIsShownByTheComponent(): void
    {
        $product = $this->product(1);
        $this->getService(CheckoutFacade::class)->holdStock($this->cartWith($product)[0]);
        [$buyer] = $this->buyerWithALineOf($product);

        $component = $this->getService('ux.twig_component.component_factory')->get('Organisms:Invoice:Base');
        $component->setLiveResponder(new LiveResponder());
        $component->selectInvoiceAddress($this->addressOf($buyer));
        // The template of the component, with the component the action just ran on (what a live re-render shows).
        $twig = $this->getService('twig');
        $html = $twig->render('@Flexy/Organisms/Invoice/Base.html.twig', ['this' => $component, 'attributes' => new ComponentAttributes([], $twig->getRuntime(EscaperRuntime::class))]);

        self::assertStringContainsString('data-testid="checkout-stock-refusal"', $html);
        self::assertStringContainsString('is held in another cart', $html);
    }

    /**
     * A buyer with a cart of one unit of the product, in the session, and an address in their book.
     *
     * @return array{0: Customer, 1: CartItem}
     */
    private function buyerWithALineOf(\Thelia\Model\Product $product): array
    {
        $buyer = $this->customer();
        [, $line] = $this->inTheSession($this->cartWith($product, $buyer), $buyer);
        $this->addressOf($buyer);

        return [$buyer, $line];
    }

    private function addressOf(Customer $buyer): int
    {
        static $addresses = [];

        return $addresses[$buyer->getId()] ??= (int) $this->fixtures()->address($buyer, $this->fixtures()->country())->getId();
    }

    private function choose(string $componentName, Customer $buyer): object
    {
        $factory = $this->getService('ux.twig_component.component_factory');
        self::assertInstanceOf(ComponentFactory::class, $factory);
        $component = $factory->get($componentName);
        $component->setLiveResponder(new LiveResponder());

        match ($componentName) {
            'Organisms:Delivery:Base' => $component->selectDeliveryAddress($this->addressOf($buyer)),
            'Organisms:Invoice:Base' => $component->selectInvoiceAddress($this->addressOf($buyer)),
            default => $component->selectPaymentModuleId((int) ModuleQuery::create()->findOneByCode('Cheque')?->getId()),
        };

        return $component;
    }

    private function setReservedUntil(CartItem $line, string $interval): void
    {
        Propel::getConnection('TheliaMain')->exec('UPDATE cart_item SET reserved_until = NOW() + INTERVAL '.$interval.' WHERE id = '.(int) $line->getId());
    }
}
