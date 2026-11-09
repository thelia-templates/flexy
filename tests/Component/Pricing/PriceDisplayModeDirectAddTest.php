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

namespace FlexyBundle\Tests\Component\Pricing;

use FlexyBundle\Components\Layouts\ProductDetails\Base as ProductDetails;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\ComponentFactory;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Pricing\PriceVisibility\PriceDisplayMode;
use Thelia\Form\Definition\FrontForm;
use Thelia\Model\CartItemQuery;
use Thelia\Model\CartQuery;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;
use Thelia\Tests\Integration\Domain\Pricing\PriceVisibility\PriceDisplayFixtures;

/**
 * The sheet hides the add to cart of an item nobody may buy, and the shop refuses the add anyway:
 * a request the browser forges, the form of the sheet posted by hand, is answered by the sentence of
 * the cart and leaves the cart as it was. The same holds for a visitor in a shop that shows its
 * prices to customers only, and not for a customer.
 */
final class PriceDisplayModeDirectAddTest extends IntegrationTestCase
{
    use PriceDisplayFixtures;

    private FixtureFactory $factory;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->createFixtureFactory();
        $this->product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->factory->currency(), ['baseQuantity' => 10, 'title' => 'Direct add product']);
        $this->forgetPriceDisplayMemos();

        // The cart form of the product page is protected by the stateless token of the theme: it is
        // valid for a request that comes from the page itself.
        $request = $this->getService(RequestStack::class)->getCurrentRequest();
        $request?->headers->set('Origin', $request->getSchemeAndHttpHost());
    }

    protected function tearDown(): void
    {
        $this->session()->setCustomerUser(null);
        $this->session()->setSessionCart(null);
        $this->forgetPriceDisplayMemos();

        parent::tearDown();
    }

    public function testTheFormOfASheetOnRequestPostedByHandIsRefusedAndTheCartIsUnchanged(): void
    {
        $this->productShows($this->product, PriceDisplayMode::OnRequest);
        $cart = $this->factory->cart();
        $this->session()->setSessionCart(CartQuery::create()->findPk($cart->getId()));
        $page = $this->sheet();

        $page->save();

        self::assertTrue($page->cartRefused);
        self::assertSame(0, CartItemQuery::create()->filterByCartId($cart->getId())->count());
    }

    public function testACustomerWhoIsShownThePricesAddsTheItemThroughTheSameForm(): void
    {
        $this->shopShows(PriceDisplayMode::LoggedIn);
        $customer = $this->factory->customer($this->factory->customerTitle());
        $cart = $this->factory->cart($customer);
        $this->session()->setCustomerUser($customer);
        $this->session()->setSessionCart(CartQuery::create()->findPk($cart->getId()));
        $this->forgetPriceDisplayMemos();
        $page = $this->sheet();

        $page->save();

        self::assertFalse($page->cartRefused);
        self::assertSame(1, CartItemQuery::create()->filterByCartId($cart->getId())->count());
    }

    public function testAVisitorOfAShopThatShowsItsPricesToCustomersOnlyIsRefused(): void
    {
        $this->shopShows(PriceDisplayMode::LoggedIn);
        $cart = $this->factory->cart();
        $this->session()->setSessionCart(CartQuery::create()->findPk($cart->getId()));
        $page = $this->sheet();

        $page->save();

        self::assertTrue($page->cartRefused);
        self::assertSame(0, CartItemQuery::create()->filterByCartId($cart->getId())->count());
    }

    private function sheet(): ProductDetails
    {
        $factory = $this->getService('ux.twig_component.component_factory');
        self::assertInstanceOf(ComponentFactory::class, $factory);
        $page = $factory->get('Layouts:ProductDetails:Base');
        self::assertInstanceOf(ProductDetails::class, $page);
        $page->mount(['id' => (int) $this->product->getId(), 'i18ns' => ['title' => 'Direct add product']]);
        $page->formValues = [
            'product' => (int) $this->product->getId(),
            'product_sale_elements_id' => (int) $this->product->getDefaultSaleElements()->getId(),
            'quantity' => 1,
            'append' => 1,
            'newness' => 0,
            '_token' => $this->getService(FormServiceInterface::class)->getFormByName(FrontForm::CART_ADD)->createView()->children['_token']->vars['value'],
        ];

        return $page;
    }

    private function session(): Session
    {
        $session = $this->getService(RequestStack::class)->getCurrentRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);

        return $session;
    }
}
