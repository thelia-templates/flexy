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
use Symfony\UX\LiveComponent\LiveResponder;
use Symfony\UX\TwigComponent\ComponentFactory;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Shipping\DestinationRestriction\DestinationRestrictionFacade;
use Thelia\Model\CartQuery;
use Thelia\Model\Country;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;
use Twig\Environment;

/**
 * A delivery address the destination restrictions refuse for products of the cart is a refusal the buyer reads, not a
 * 500: the step names the products, in the singular or the plural, whether the buyer picked the address or the step
 * selected the default one on its own.
 *
 * Boots the host kernel on the test database `bin/test-prepare` creates.
 */
final class DeliveryBlockedByDestinationRenderTest extends IntegrationTestCase
{
    private const SENTENCE_ONE = 'Remove it from your cart or choose another address.';
    private const SENTENCE_MANY = 'Remove them from your cart or choose another address.';

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(DestinationRestrictionFacade::class)) {
            self::markTestSkipped('The core published does not carry the destination restrictions yet.');
        }
    }

    public function testTheDefaultAddressTheStepSelectsIsRefusedWithoutCrashing(): void
    {
        $factory = $this->createFixtureFactory();
        $germany = $this->country($factory);
        $this->cartToWhichTheDefaultAddressIs($factory, $germany, ['Calvados']);

        $message = $this->warning();

        self::assertStringContainsString('Calvados (Alcohol licence) cannot be shipped to this address.', $message);
        self::assertStringContainsString(self::SENTENCE_ONE, $message);
    }

    public function testSeveralProductsAreAskedToBeRemovedInThePlural(): void
    {
        $factory = $this->createFixtureFactory();
        $germany = $this->country($factory);
        $this->cartToWhichTheDefaultAddressIs($factory, $germany, ['Calvados', 'Armagnac']);

        $message = $this->warning();

        self::assertStringContainsString('Calvados (Alcohol licence), Armagnac (Alcohol licence) cannot be shipped to this address.', $message);
        self::assertStringContainsString(self::SENTENCE_MANY, $message);
    }

    public function testAnAddressTheBuyerPicksIsRefusedWithoutWritingItAndWithoutCrashing(): void
    {
        $factory = $this->createFixtureFactory();
        $germany = $this->country($factory);
        $customer = $factory->customer($factory->customerTitle());
        $address = $factory->address($customer, $germany);
        $cart = $this->cartWith($factory, $customer, ['Calvados'], $germany);

        $component = $this->getService('ux.twig_component.component_factory');
        self::assertInstanceOf(ComponentFactory::class, $component);
        $delivery = $component->get('Organisms:Delivery:Base');
        $delivery->setLiveResponder(new LiveResponder());

        $delivery->selectDeliveryAddress($address->getId());

        self::assertSame(['Calvados (Alcohol licence)'], $delivery->restrictedProducts);
        self::assertNull(CartQuery::create()->findPk($cart->getId())?->getAddressDeliveryId(), 'the refused address is not written to the cart');
    }

    public function testAnAddressTheCartCanShipToShowsNoWarning(): void
    {
        $factory = $this->createFixtureFactory();
        $germany = $this->country($factory);
        $this->cartToWhichTheDefaultAddressIs($factory, $factory->country(), ['Calvados'], $germany);

        $twig = $this->getService('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $html = $twig->createTemplate('{{ component("Organisms:Delivery:Base") }}', 'delivery-step.html.twig')->render();

        self::assertCount(0, (new Crawler($html))->filter('[data-testid="delivery-destination-restricted"]'));
    }

    public function testATitleIsPrintedAsTextNeverAsMarkup(): void
    {
        $factory = $this->createFixtureFactory();
        $this->cartToWhichTheDefaultAddressIs($factory, $this->country($factory), ['Calvados <b>XL</b>']);

        $crawler = $this->alert();

        self::assertStringContainsString('Calvados <b>XL</b>', $crawler->text());
        self::assertCount(0, $crawler->filter('b'));
    }

    private function country(FixtureFactory $factory): Country
    {
        return $factory->country(['isocode' => '9'.random_int(10, 99), 'isoalpha2' => 'X'.\chr(random_int(65, 90)), 'isoalpha3' => 'X'.\chr(random_int(65, 90)).\chr(random_int(65, 90))]);
    }

    /**
     * The cart has no delivery address and the customer a default one in $default; the products are refused in $refused.
     *
     * @param list<string> $titles
     */
    private function cartToWhichTheDefaultAddressIs(FixtureFactory $factory, Country $default, array $titles, ?Country $refused = null): void
    {
        $customer = $factory->customer($factory->customerTitle());
        $factory->address($customer, $default)->setIsDefault(1)->save($this->getPropelConnection());
        $this->cartWith($factory, $customer, $titles, $refused ?? $default);
    }

    /**
     * @param list<string> $titles
     */
    private function cartWith(FixtureFactory $factory, \Thelia\Model\Customer $customer, array $titles, Country $refused): \Thelia\Model\Cart
    {
        $cart = $factory->cart($customer);
        $facade = $this->getService(DestinationRestrictionFacade::class);
        $group = $facade->save(null, 'test-'.bin2hex(random_bytes(4)), 0, true, ['en_US' => ['title' => 'Alcohol', 'reason' => 'Alcohol licence']]);
        $facade->addDestination($group->getId(), $refused->getId());

        foreach ($titles as $title) {
            /** @var Product $product */
            $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['title' => $title]);
            $factory->cartItem($cart, $product);
            $facade->attachProduct($group->getId(), $product->getId());
        }

        $session = $this->getService(RequestStack::class)->getMainRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);
        $session->setCustomerUser($customer);
        $session->setSessionCart($cart);

        return $cart;
    }

    private function alert(): Crawler
    {
        $twig = $this->getService('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $html = $twig->createTemplate('{{ component("Organisms:Delivery:Base") }}', 'delivery-step.html.twig')->render();
        $alert = (new Crawler($html))->filter('[data-testid="delivery-destination-restricted"] .SnackBar-message');
        self::assertCount(1, $alert, 'the step names the refused products, once');

        return $alert;
    }

    private function warning(): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $this->alert()->text()));
    }
}
