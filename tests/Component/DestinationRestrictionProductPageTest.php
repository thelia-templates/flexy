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

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Shipping\DestinationRestriction\DestinationRestrictionFacade;
use Thelia\Model\Country;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;
use Twig\Environment;

/**
 * The sheet of a product under a destination restriction says where it ships, by country name, with the reason of
 * the group; and, for a visitor whose address is known, that the product does not go there.
 *
 * Boots the host kernel on the test database `bin/test-prepare` creates.
 */
final class DestinationRestrictionProductPageTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(DestinationRestrictionFacade::class)) {
            self::markTestSkipped('The core published does not carry the destination restrictions yet.');
        }
    }

    public function testTheRefusedDestinationsAreListedByNameWithTheReason(): void
    {
        $factory = $this->createFixtureFactory();
        $germany = $this->country($factory, 'Germany');
        $product = $this->restrictedProduct($factory, 0, [$germany]);

        $text = $this->mention($product);

        self::assertStringContainsString('Cannot be delivered to: Germany. Alcohol licence', $text);
        self::assertStringNotContainsString('Can only be delivered', $text);
        self::assertStringNotContainsString('DE', $text, 'names, not codes');
    }

    public function testTheOnlyServedDestinationsAreListedAsSuch(): void
    {
        $factory = $this->createFixtureFactory();
        $belgium = $this->country($factory, 'Belgium');
        $product = $this->restrictedProduct($factory, 1, [$belgium]);

        $text = $this->mention($product);

        self::assertStringContainsString('Can only be delivered to: Belgium.', $text);
        self::assertStringNotContainsString('Cannot be delivered', $text);
    }

    public function testAReasonIsPrintedAsTextNeverAsMarkup(): void
    {
        $factory = $this->createFixtureFactory();
        $product = $this->restrictedProduct($factory, 0, [$this->country($factory, 'Germany')], 'Licence <b>needed</b>');

        $crawler = $this->page($product);

        self::assertStringContainsString('Licence <b>needed</b>', $crawler->filter('[data-testid="destination-restrictions"]')->text());
        self::assertCount(0, $crawler->filter('[data-testid="destination-restrictions"] b'));
    }

    public function testAKnownAddressTheProductDoesNotGoToIsToldExplicitly(): void
    {
        $factory = $this->createFixtureFactory();
        $germany = $this->country($factory, 'Germany');
        $product = $this->restrictedProduct($factory, 0, [$germany]);
        $this->visitorWithDefaultAddressIn($factory, $germany);

        $crawler = $this->page($product);

        self::assertCount(1, $crawler->filter('[data-testid="destination-refused"]'));
        self::assertStringContainsString('This product cannot be delivered to your address.', $crawler->filter('[data-testid="destination-refused"]')->text());
    }

    public function testAKnownAddressTheProductGoesToIsNotRefused(): void
    {
        $factory = $this->createFixtureFactory();
        $germany = $this->country($factory, 'Germany');
        $product = $this->restrictedProduct($factory, 0, [$germany]);
        $this->visitorWithDefaultAddressIn($factory, $this->country($factory, 'Spain'));

        self::assertCount(0, $this->page($product)->filter('[data-testid="destination-refused"]'));
    }

    public function testWithoutAKnownAddressTheListsAreShownAndNothingIsRefused(): void
    {
        $factory = $this->createFixtureFactory();
        $product = $this->restrictedProduct($factory, 0, [$this->country($factory, 'Germany')]);

        $crawler = $this->page($product);

        self::assertCount(1, $crawler->filter('[data-testid="destination-restrictions"]'));
        self::assertCount(0, $crawler->filter('[data-testid="destination-refused"]'));
    }

    public function testAProductWithoutRestrictionSaysNothing(): void
    {
        $factory = $this->createFixtureFactory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['title' => 'Free product']);

        self::assertCount(0, $this->page($product)->filter('[data-testid="destination-restrictions"]'));
    }

    private function country(FixtureFactory $factory, string $title): Country
    {
        $country = $factory->country(['isocode' => '9'.random_int(10, 99), 'isoalpha2' => 'X'.\chr(random_int(65, 90)), 'isoalpha3' => 'X'.\chr(random_int(65, 90)).\chr(random_int(65, 90))]);
        $country->setLocale('en_US')->setTitle($title)->save($this->getPropelConnection());

        return $country;
    }

    /**
     * @param list<Country> $countries
     */
    private function restrictedProduct(FixtureFactory $factory, int $mode, array $countries, string $reason = 'Alcohol licence'): Product
    {
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['title' => 'Calvados']);
        $facade = $this->getService(DestinationRestrictionFacade::class);
        $group = $facade->save(null, 'test-'.bin2hex(random_bytes(4)), $mode, true, ['en_US' => ['title' => 'Group', 'reason' => $reason]]);

        foreach ($countries as $country) {
            $facade->addDestination($group->getId(), $country->getId());
        }

        $facade->attachProduct($group->getId(), $product->getId());

        return $product;
    }

    private function visitorWithDefaultAddressIn(FixtureFactory $factory, Country $country): void
    {
        $customer = $factory->customer($factory->customerTitle());
        $address = $factory->address($customer, $country);
        $address->setIsDefault(1)->save($this->getPropelConnection());
        $cart = $factory->cart($customer);

        $session = $this->getService(RequestStack::class)->getMainRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);
        $session->setCustomerUser($customer);
        $session->setSessionCart($cart);
    }

    private function mention(Product $product): string
    {
        $mention = $this->page($product)->filter('[data-testid="destination-restrictions"]');
        self::assertCount(1, $mention);

        return trim((string) preg_replace('/\s+/', ' ', $mention->text()));
    }

    private function page(Product $product): Crawler
    {
        $twig = $this->getService('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $twig->addGlobal('lang_code', 'en');
        $html = $twig->createTemplate(
            '{{ component("Layouts:ProductDetails:Base", {product: {id: id, i18ns: {title: "Calvados"}}}) }}',
            'product-sheet.html.twig',
        )->render(['id' => $product->getId()]);

        return new Crawler($html);
    }
}
