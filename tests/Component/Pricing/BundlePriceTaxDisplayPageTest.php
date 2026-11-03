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

use FlexyBundle\Tests\Support\BuildsBundles;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Domain\Catalog\Bundle\BundleCompositionService;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\Flexy\CustomerSessionInjector;

/**
 * The price of a bundle priced by its components follows the display of the group of the visitor,
 * like every other price of the page: with the taxes for a group shown them, without for a group
 * shown untaxed prices, and the figure says which. The group discounts it (20 % here) as the cart
 * does, so that the page shows what the line is charged: the default composition costs 560 and is
 * sold 504, then 403.20 for a member (448 struck), 483.84 with the taxes (537.60 struck).
 */
final class BundlePriceTaxDisplayPageTest extends WebIntegrationTestCase
{
    use BuildsBundles;

    private const PRODUCT_URL = 'flexy-bundle-tax-display-product.html';

    private FixtureFactory $factory;

    private CustomerSessionInjector $injector;

    private Product $bundle;

    protected function setUp(): void
    {
        parent::setUp();

        // Not createFixtureFactory(): it leaves a request on the stack, in front of the ones the client sends.
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->injector = new CustomerSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $example = $this->exampleBundle($this->factory, $this->getService(BundleCompositionService::class));
        $this->bundle = $example['bundle'];
        $this->bundle->setLocale('en_US')->setTitle('Bundle tax display')->save($this->getPropelConnection());
        $this->bundle->setRewrittenUrl('en_US', self::PRODUCT_URL);
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);

        parent::tearDown();
    }

    public function testAMemberOfAnUntaxedGroupReadsTheBundlePriceWithoutTaxesAndItsMention(): void
    {
        $this->signedIn($this->factory->customerGroup('pro-untaxed', 20.0, false));

        $price = $this->page()->filter('.PseSelector-price');

        self::assertSame('€403.20 excl. tax', $this->normalized($price->filter('strong')->text()));
        self::assertSame('untaxed', $price->filter('.TaxMention')->attr('data-tax-display'));
        self::assertSame('€448.00', $this->normalized($price->filter('.Price-original')->text()));
        self::assertStringContainsString('€44.80', $this->normalized($price->filter('[data-testid="price-saving"]')->text()));
    }

    public function testAMemberOfATaxedGroupReadsTheBundlePriceWithItsTaxesAndItsMention(): void
    {
        $this->signedIn($this->factory->customerGroup('pro-taxed', 20.0, true));

        $price = $this->page()->filter('.PseSelector-price');

        self::assertSame('€483.84 incl. tax', $this->normalized($price->filter('strong')->text()));
        self::assertSame('taxed', $price->filter('.TaxMention')->attr('data-tax-display'));
        self::assertSame('€537.60', $this->normalized($price->filter('.Price-original')->text()));
    }

    private function signedIn(\Thelia\Model\CustomerGroup $group): void
    {
        $this->injector->setCustomer($this->factory->customer($this->factory->customerTitle(), ['customerGroup' => $group]));
    }

    private function page(): Crawler
    {
        $this->assertPageRenders('/'.self::PRODUCT_URL);

        return $this->client->getCrawler();
    }

    private function normalized(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $text)));
    }
}
