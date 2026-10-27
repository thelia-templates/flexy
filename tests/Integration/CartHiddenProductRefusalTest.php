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

namespace FlexyBundle\Tests\Integration;

use FlexyBundle\Components\Layouts\ProductDetails\Base as ProductDetails;
use FlexyBundle\Components\Organisms\Cart\Base as Cart;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\ComponentFactory;
use Thelia\Core\Event\Customer\CustomerLoginEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Form\Definition\FrontForm;
use Thelia\Model\CartItemQuery;
use Thelia\Model\CartQuery;
use Thelia\Model\Customer;
use Thelia\Model\CustomerGroup;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * A product the group of the customer does not see cannot be put in the cart through the live
 * components of the theme, whatever the browser sends: the declination of the product page is read
 * from `formValues`, which the browser writes. The shopper is told in the neutral sentence of the
 * core, and the cart is left as it was. A customer signing in with such a line in the cart of the
 * visitor they were loses it, and is told.
 *
 * The example: the professionals are restricted to Sofas; a dining table is in Dining Room.
 * Runs on an installed shop (bin/test-prepare).
 */
final class CartHiddenProductRefusalTest extends IntegrationTestCase
{
    private FixtureFactory $factory;

    private CustomerGroup $pro;

    private Product $table;

    private Product $sofa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->createFixtureFactory();
        $diningRoom = $this->factory->category();
        $sofas = $this->factory->category();
        $currency = $this->factory->currency();
        $taxRule = $this->factory->taxRule();
        $this->table = $this->factory->product($diningRoom, $taxRule, $currency, ['baseQuantity' => 10, 'title' => 'Dining table']);
        $this->sofa = $this->factory->product($sofas, $taxRule, $currency, ['baseQuantity' => 10, 'title' => 'Sofa']);
        $this->pro = $this->factory->customerGroup('pro', categoryIds: [(int) $sofas->getId()]);
        $this->getService('services_resetter')->reset();

        // The cart form of the product page is protected by the stateless token of the theme: it is
        // valid for a request that comes from the page itself.
        $request = $this->getService(RequestStack::class)->getCurrentRequest();
        $request?->headers->set('Origin', $request->getSchemeAndHttpHost());
    }

    protected function tearDown(): void
    {
        $this->session()->setCustomerUser(null);
        $this->session()->setSessionCart(null);
        $this->getService('services_resetter')->reset();

        parent::tearDown();
    }

    public function testAForgedDeclinationOfAClosedProductIsRefusedWithTheNeutralSentenceAndTheCartIsUnchanged(): void
    {
        $cart = $this->signInWithAnEmptyCart($this->professional());
        $page = $this->productPageOf($this->sofa);
        // What the browser can write into the component state: the product and the declination of one
        // the visitor does not see (the form only checks that the two belong together).
        $page->formValues['product'] = (int) $this->table->getId();
        $page->formValues['product_sale_elements_id'] = $this->pseId($this->table);

        $page->save();

        self::assertTrue($page->cartRefused);
        self::assertSame('This product is not available.', $page->cartRefusalReason);
        self::assertSame(0, CartItemQuery::create()->filterByCartId($cart->getId())->count());
    }

    public function testTheDeclinationOfTheProductOfThePageIsAddedForTheSameCustomer(): void
    {
        $cart = $this->signInWithAnEmptyCart($this->professional());
        $page = $this->productPageOf($this->sofa);

        $page->save();

        self::assertFalse($page->cartRefused);
        self::assertSame(1, CartItemQuery::create()->filterByCartId($cart->getId())->count());
    }

    public function testTheDefaultGroupAddsTheTableThroughTheSamePage(): void
    {
        $cart = $this->signInWithAnEmptyCart($this->consumer());
        $page = $this->productPageOf($this->table);

        $page->save();

        self::assertFalse($page->cartRefused);
        self::assertSame(1, CartItemQuery::create()->filterByCartId($cart->getId())->count());
    }

    public function testAnUndoOfTheCartThatPutsAClosedProductBackIsRefused(): void
    {
        $cart = $this->signInWithAnEmptyCart($this->professional());
        $component = $this->component('Organisms:Cart:Base', Cart::class);
        $component->pendingDelete = [
            'title' => 'Dining table',
            'productId' => (int) $this->table->getId(),
            'pseId' => $this->pseId($this->table),
            'quantity' => 1,
            'imageId' => null,
        ];

        $component->restoreCartItem($this->pseId($this->table), (int) $this->table->getId(), 1);

        self::assertSame('restore', $component->refusal);
        self::assertSame('This product is not available.', $component->refusalReason);
        self::assertSame(0, CartItemQuery::create()->filterByCartId($cart->getId())->count());
    }

    public function testASignInTakesTheClosedLineOutOfTheVisitorsCartAndTellsTheShopper(): void
    {
        $cart = $this->factory->cart();
        $this->factory->cartItem($cart, $this->table);
        $this->factory->cartItem($cart, $this->sofa);
        $this->session()->setSessionCart(CartQuery::create()->findPk($cart->getId()));
        $professional = $this->professional();

        $this->getService('services_resetter')->reset();
        $this->getService('event_dispatcher')->dispatch(new CustomerLoginEvent($professional), TheliaEvents::CUSTOMER_LOGIN);

        $lines = CartItemQuery::create()->filterByCartId($cart->getId())->find();
        self::assertCount(1, $lines);
        self::assertSame((int) $this->sofa->getId(), (int) $lines->getFirst()->getProductId());
        $notices = $this->session()->getFlashBag()->peek('error');
        self::assertCount(1, $notices);
        self::assertStringContainsString('Dining table', $notices[0]);
        self::assertStringNotContainsString('Sofa', $notices[0]);
    }

    private function signInWithAnEmptyCart(Customer $customer): \Thelia\Model\Cart
    {
        $cart = $this->factory->cart($customer);
        $this->session()->setCustomerUser($customer);
        $this->session()->setSessionCart(CartQuery::create()->findPk($cart->getId()));
        $this->getService('services_resetter')->reset();

        return $cart;
    }

    private function productPageOf(Product $product): ProductDetails
    {
        $page = $this->component('Layouts:ProductDetails:Base', ProductDetails::class);
        $page->mount(['id' => (int) $product->getId(), 'i18ns' => ['title' => (string) $product->setLocale('en_US')->getTitle()]]);
        // The token the page would have printed in the form: what the browser sends back with the rest.
        $page->formValues = [
            'product' => (int) $product->getId(),
            'product_sale_elements_id' => $this->pseId($product),
            'quantity' => 1,
            'append' => 1,
            'newness' => 0,
            '_token' => $this->getService(FormServiceInterface::class)->getFormByName(FrontForm::CART_ADD)->createView()->children['_token']->vars['value'],
        ];

        return $page;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function component(string $name, string $class): object
    {
        $factory = $this->getService('ux.twig_component.component_factory');
        self::assertInstanceOf(ComponentFactory::class, $factory);
        $component = $factory->get($name);
        self::assertInstanceOf($class, $component);

        return $component;
    }

    private function pseId(Product $product): int
    {
        return (int) $product->getDefaultSaleElements()->getId();
    }

    private function professional(): Customer
    {
        return $this->factory->customer($this->factory->customerTitle(), ['customerGroup' => $this->pro]);
    }

    private function consumer(): Customer
    {
        return $this->factory->customer($this->factory->customerTitle());
    }

    private function session(): Session
    {
        $stack = $this->getService(RequestStack::class);
        \assert($stack instanceof RequestStack);
        $session = $stack->getCurrentRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);

        return $session;
    }
}
