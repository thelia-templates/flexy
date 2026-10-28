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

use FlexyBundle\Service\SitemapGenerator;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Model\Customer;
use Thelia\Model\Product;
use Thelia\Model\Sale;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The sitemap is cached once for every visitor, so the private drops are read for a visitor named
 * on nothing: a customer named on a running private drop who regenerates the file must not add the
 * products of that drop to it for everybody.
 */
final class SitemapReservedSaleTest extends IntegrationTestCase
{
    private FixtureFactory $factory;

    private Customer $named;

    private Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new FixtureFactory($this->getPropelConnection());

        $category = $this->factory->category();
        $category->setLocale('en_US')->setTitle('Sitemap reserved category')->save($this->getPropelConnection());
        $category->setRewrittenUrl('en_US', 'flexy-sitemap-reserved-category.html');

        $product = $this->factory->product($category, $this->factory->taxRule(), $this->factory->currency(), ['basePrice' => 10.0, 'baseQuantity' => 5]);
        $product->setLocale('en_US')->setTitle('Sitemap reserved product')->save($this->getPropelConnection());
        $product->setRewrittenUrl('en_US', 'flexy-sitemap-reserved-product.html');

        $this->named = $this->factory->customer($this->factory->customerTitle());
        $this->sale = $this->privateDrop($product, new \DateTimeImmutable('+1 day'));
    }

    public function testACustomerNamedOnARunningPrivateDropRegeneratingTheProductsSitemapDoesNotAddItsProducts(): void
    {
        $this->signIn($this->named);

        self::assertStringNotContainsString('flexy-sitemap-reserved-product.html', $this->generate('products'));
    }

    public function testAnAnonymousVisitorRegeneratingTheProductsSitemapDoesNotGetTheProductsOfARunningPrivateDrop(): void
    {
        self::assertStringNotContainsString('flexy-sitemap-reserved-product.html', $this->generate('products'));
    }

    public function testTheProductsOfAnEndedPrivateDropAreInTheProductsSitemap(): void
    {
        $this->sale->setEndDate(new \DateTime('-1 hour'))->save($this->getPropelConnection());
        $this->signIn($this->named);

        self::assertStringContainsString('flexy-sitemap-reserved-product.html', $this->generate('products'));
    }

    private function privateDrop(Product $product, \DateTimeInterface $end): Sale
    {
        $sale = $this->factory->sale([
            'active' => true,
            'startDate' => new \DateTimeImmutable('-1 day'),
            'endDate' => $end,
            'audienceMode' => Sale::AUDIENCE_MODE_CUSTOMERS,
            'hideProducts' => true,
        ]);
        $this->factory->saleProduct($sale, $product);
        $this->factory->saleCustomer($sale, $this->named);

        return $sale;
    }

    private function signIn(Customer $customer): void
    {
        $session = static::getContainer()->get('request_stack')->getMainRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);
        $session->setCustomerUser($customer);
    }

    private function generate(string $section): string
    {
        $container = static::getContainer();
        $container->get('thelia.url.manager');

        $template = $container->get(TemplateHelperInterface::class)->getActiveFrontTemplate();
        $parser = $container->get(ParserResolver::class)->getParser($template->getAbsolutePath(), null);
        $parser->setTemplateDefinition($template, true);

        return (string) $container->get(SitemapGenerator::class)->generate($parser, $section, true)->get();
    }
}
