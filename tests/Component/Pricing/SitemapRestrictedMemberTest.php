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
use Thelia\Model\Category;
use Thelia\Model\CustomerGroup;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The sitemap is cached once for every visitor: it lists what a visitor of the default customer
 * group can reach. The default group here has no list, so it reaches every product; a member of a
 * group restricted to one category who regenerates the file must not take the others out of it.
 */
final class SitemapRestrictedMemberTest extends IntegrationTestCase
{
    private FixtureFactory $factory;

    private CustomerGroup $pro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new FixtureFactory($this->getPropelConnection());

        $open = $this->category('Sitemap member open category', 'flexy-sitemap-member-open-category.html');
        $other = $this->category('Sitemap member other category', 'flexy-sitemap-member-other-category.html');

        $this->pro = $this->factory->customerGroup('sitemap-restricted-pro', categoryIds: [(int) $open->getId()]);

        $this->product($open, 'Sitemap member open product', 'flexy-sitemap-member-open-product.html');
        $this->product($other, 'Sitemap member other product', 'flexy-sitemap-member-other-product.html');
    }

    public function testAMemberOfARestrictedGroupRegeneratingTheProductsSitemapKeepsTheProductsTheDefaultGroupReaches(): void
    {
        $this->signIn($this->pro);

        $xml = $this->generate('products');

        self::assertStringContainsString('flexy-sitemap-member-open-product.html', $xml);
        self::assertStringContainsString('flexy-sitemap-member-other-product.html', $xml);
    }

    public function testAMemberOfARestrictedGroupRegeneratingTheCategoriesSitemapKeepsTheCategoriesTheDefaultGroupReaches(): void
    {
        $this->signIn($this->pro);

        $xml = $this->generate('categories');

        self::assertStringContainsString('flexy-sitemap-member-open-category.html', $xml);
        self::assertStringContainsString('flexy-sitemap-member-other-category.html', $xml);
    }

    private function signIn(CustomerGroup $group): void
    {
        $customer = $this->factory->customer($this->factory->customerTitle(), ['customerGroup' => $group]);
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

    private function category(string $title, string $url): Category
    {
        $category = $this->factory->category();
        $category->setLocale('en_US')->setTitle($title)->save($this->getPropelConnection());
        $category->setRewrittenUrl('en_US', $url);

        return $category;
    }

    private function product(Category $category, string $title, string $url): Product
    {
        $product = $this->factory->product($category, $this->factory->taxRule(), $this->factory->currency(), ['basePrice' => 10.0, 'baseQuantity' => 5]);
        $product->setLocale('en_US')->setTitle($title)->save($this->getPropelConnection());
        $product->setRewrittenUrl('en_US', $url);

        return $product;
    }
}
