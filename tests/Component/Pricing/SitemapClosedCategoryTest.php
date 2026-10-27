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
use Thelia\Model\CustomerGroupCategory;
use Thelia\Model\CustomerGroupQuery;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The sitemap is cached once for every visitor and crawled anonymously: it lists what a visitor
 * of the default customer group can reach, whoever triggered its generation. When the default
 * group carries a white list of categories, a category out of it, and the products that have no
 * open category, are left out, even when the one who regenerates the file belongs to a group
 * that sees them.
 */
final class SitemapClosedCategoryTest extends IntegrationTestCase
{
    private FixtureFactory $factory;

    private CustomerGroup $club;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new FixtureFactory($this->getPropelConnection());

        $open = $this->category('Sitemap open category', 'flexy-sitemap-open-category.html');
        $closed = $this->category('Sitemap closed category', 'flexy-sitemap-closed-category.html');

        // The list is on the default group, the one a crawler belongs to; the club has none and sees everything.
        $listed = new CustomerGroupCategory();
        $listed->setCustomerGroupId((int) CustomerGroupQuery::findDefault()->getId());
        $listed->setCategoryId($open->getId());
        $listed->save($this->getPropelConnection());
        CustomerGroupQuery::findDefault()->setRestrictsCategories(1)->save($this->getPropelConnection());

        $this->club = $this->factory->customerGroup('sitemap-club');

        $this->product($open, 'Sitemap open product', 'flexy-sitemap-open-product.html');
        $this->product($closed, 'Sitemap closed product', 'flexy-sitemap-closed-product.html');
    }

    public function testTheCategoriesSitemapLeavesOutACategoryOutOfTheWhiteListOfTheDefaultGroup(): void
    {
        $xml = $this->generate('categories');

        self::assertStringContainsString('flexy-sitemap-open-category.html', $xml);
        self::assertStringNotContainsString('flexy-sitemap-closed-category.html', $xml);
    }

    public function testTheProductsSitemapLeavesOutAProductThatHasNoOpenCategory(): void
    {
        $xml = $this->generate('products');

        self::assertStringContainsString('flexy-sitemap-open-product.html', $xml);
        self::assertStringNotContainsString('flexy-sitemap-closed-product.html', $xml);
    }

    public function testAMemberOfAnUnrestrictedGroupRegeneratingTheCategoriesSitemapDoesNotPutTheClosedCategoryInIt(): void
    {
        $this->signIn($this->club);

        $xml = $this->generate('categories');

        self::assertStringNotContainsString('flexy-sitemap-closed-category.html', $xml);
        self::assertStringContainsString('flexy-sitemap-open-category.html', $xml);
    }

    public function testAMemberOfAnUnrestrictedGroupRegeneratingTheProductsSitemapDoesNotPutTheClosedProductInIt(): void
    {
        $this->signIn($this->club);

        $xml = $this->generate('products');

        self::assertStringNotContainsString('flexy-sitemap-closed-product.html', $xml);
        self::assertStringContainsString('flexy-sitemap-open-product.html', $xml);
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
