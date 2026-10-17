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

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Catalog\Product\ProductFacade;
use Thelia\Domain\Shipping\ShippingFacade;
use Thelia\Model\Area;
use Thelia\Model\AreaDeliveryModule;
use Thelia\Model\Country;
use Thelia\Model\CountryArea;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Module\BaseModule;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;
use Twig\Environment;

/**
 * A cart whose products leave no carrier at all gets those products named, in place of a sentence that says nothing
 * about what to do. A cart nobody serves for another reason keeps the sentence it always had.
 *
 * Boots the host kernel on the test database `bin/test-prepare` creates.
 */
final class DeliveryBlockedByProductsRenderTest extends IntegrationTestCase
{
    private const SENTENCE = 'No carrier can ship: %s. Remove them from your cart to complete your order.';
    private const SENTENCE_ONE = 'No carrier can ship: %s. Remove it from your cart to complete your order.';
    private const GENERIC = 'No delivery method is available for this order.';

    protected function setUp(): void
    {
        parent::setUp();

        if (!method_exists(ShippingFacade::class, 'productsBlockingEveryCarrier')) {
            self::markTestSkipped('The core published does not carry the shipping rules of a product yet.');
        }
    }

    public function testTheProductsThatExcludeEveryCarrierAreNamed(): void
    {
        $factory = $this->createFixtureFactory();
        $country = $factory->country();
        $this->serveWithEveryCarrier($country);
        $cart = $this->cartOf($factory, $country, ['Garden furnace', 'Gas cylinder']);

        foreach ($cart['products'] as $product) {
            foreach ($this->carriers() as $carrier) {
                $this->getService(ProductFacade::class)->excludeDeliveryModule($product->getId(), $carrier->getId());
            }
        }

        $message = $this->deliveryMessage();

        self::assertStringContainsString(\sprintf(self::SENTENCE, 'Garden furnace, Gas cylinder'), $message);
        self::assertStringNotContainsString(self::GENERIC, $message);
    }

    public function testASingleProductIsAskedToBeRemovedInTheSingular(): void
    {
        $factory = $this->createFixtureFactory();
        $country = $factory->country();
        $this->serveWithEveryCarrier($country);
        $cart = $this->cartOf($factory, $country, ['Garden furnace']);

        foreach ($this->carriers() as $carrier) {
            $this->getService(ProductFacade::class)->excludeDeliveryModule($cart['products'][0]->getId(), $carrier->getId());
        }

        self::assertStringContainsString(\sprintf(self::SENTENCE_ONE, 'Garden furnace'), $this->deliveryMessage());
    }

    public function testATitleIsPrintedAsTextNeverAsMarkup(): void
    {
        $factory = $this->createFixtureFactory();
        $country = $factory->country();
        $this->serveWithEveryCarrier($country);
        $cart = $this->cartOf($factory, $country, ['Furnace <b>XL</b>']);

        foreach ($this->carriers() as $carrier) {
            $this->getService(ProductFacade::class)->excludeDeliveryModule($cart['products'][0]->getId(), $carrier->getId());
        }

        $crawler = $this->deliveryAlert();

        self::assertStringContainsString('Furnace <b>XL</b>', $crawler->text());
        self::assertCount(0, $crawler->filter('b'));
    }

    public function testACartNoCarrierServesForAnotherReasonKeepsTheGenericSentence(): void
    {
        $factory = $this->createFixtureFactory();
        // No carrier serves this country, and no product excludes any: nothing to name.
        $this->cartOf($factory, $factory->country(), ['Garden furnace']);

        $message = $this->deliveryMessage();

        self::assertStringContainsString(self::GENERIC, $message);
        self::assertStringNotContainsString('No carrier can ship', $message);
    }

    /**
     * @param list<string> $titles
     *
     * @return array{products: list<\Thelia\Model\Product>}
     */
    private function cartOf(FixtureFactory $factory, Country $country, array $titles): array
    {
        $customer = $factory->customer($factory->customerTitle());
        $cartAddress = $factory->cartAddress(null, $country);
        $cart = $factory->cart($customer);
        $cart->setAddressDeliveryId($cartAddress->getId())->save($this->getPropelConnection());
        $products = [];

        foreach ($titles as $title) {
            $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['title' => $title]);
            $factory->cartItem($cart, $product);
            $products[] = $product;
        }

        $session = $this->getService(RequestStack::class)->getMainRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);
        $session->setCustomerUser($customer);
        $session->setSessionCart($cart);

        return ['products' => $products];
    }

    private function deliveryAlert(): Crawler
    {
        // A live component takes its id from the template that calls it: it is rendered from one, as a page does.
        $twig = $this->getService('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $html = $twig->createTemplate('{{ component("Organisms:Delivery:Base") }}', 'delivery-step.html.twig')->render();
        $alert = (new Crawler($html))->filter('.SnackBar-message');
        self::assertCount(1, $alert, 'the step announces that nothing can be delivered, once');

        return $alert;
    }

    private function deliveryMessage(): string
    {
        return $this->deliveryAlert()->text();
    }

    /**
     * @return list<Module>
     */
    private function carriers(): array
    {
        return ModuleQuery::create()->filterByType(BaseModule::DELIVERY_MODULE_TYPE)->filterByActivate(1)->find()->getData();
    }

    private function serveWithEveryCarrier(Country $country): void
    {
        $area = (new Area())->setName('Blocked by products test area');
        $area->save();
        (new CountryArea())->setAreaId($area->getId())->setCountryId($country->getId())->save();

        foreach ($this->carriers() as $carrier) {
            (new AreaDeliveryModule())->setAreaId($area->getId())->setDeliveryModuleId($carrier->getId())->save();
        }
    }
}
