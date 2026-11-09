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
use Thelia\Domain\Catalog\Restriction\CatalogRestrictionMode;
use Thelia\Model\CatalogRestriction;
use Thelia\Model\Category;
use Thelia\Model\CustomerGroup;
use Thelia\Model\CustomerGroupQuery;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The sitemap is cached once for every visitor: it lists what a visitor of the default customer
 * group reaches. A catalog restricted for that group leaves the closed product out of the file; a
 * restriction written for another group, whose member regenerates the file, takes nothing out of it.
 */
final class PriceListSitemapTest extends IntegrationTestCase
{
    private FixtureFactory $factory;

    private Product $open;

    private Product $closed;

    private CustomerGroup $pro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new FixtureFactory($this->getPropelConnection());
        $category = $this->factory->category();
        $category->setLocale('en_US')->setTitle('Sitemap restriction category')->save($this->getPropelConnection());
        $category->setRewrittenUrl('en_US', 'flexy-sitemap-restriction-category.html');

        $this->open = $this->product($category, 'Sitemap restriction open product', 'flexy-sitemap-restriction-open.html');
        $this->closed = $this->product($category, 'Sitemap restriction closed product', 'flexy-sitemap-restriction-closed.html');
        $this->pro = $this->factory->customerGroup('sitemap-restriction-pro');
        $this->getService('services_resetter')->reset();
    }

    public function testAProductClosedToTheDefaultGroupIsNotInTheSitemap(): void
    {
        $this->deny($this->defaultGroup(), $this->closed);

        $xml = $this->generate('products');

        self::assertStringContainsString('flexy-sitemap-restriction-open.html', $xml);
        self::assertStringNotContainsString('flexy-sitemap-restriction-closed.html', $xml);
    }

    public function testNothingIsLeftOutWhenTheDefaultGroupHasNoRestriction(): void
    {
        $xml = $this->generate('products');

        self::assertStringContainsString('flexy-sitemap-restriction-open.html', $xml);
        self::assertStringContainsString('flexy-sitemap-restriction-closed.html', $xml);
    }

    public function testAMemberOfAnotherRestrictedGroupRegeneratingTheFileTakesNothingOutOfIt(): void
    {
        $this->deny($this->pro, $this->closed);
        $customer = $this->factory->customer($this->factory->customerTitle(), ['customerGroup' => $this->pro]);
        $session = static::getContainer()->get('request_stack')->getMainRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);
        $session->setCustomerUser($customer);

        $xml = $this->generate('products');

        self::assertStringContainsString('flexy-sitemap-restriction-closed.html', $xml, 'The file is for every visitor: a member of another group does not narrow it.');
    }

    private function defaultGroup(): CustomerGroup
    {
        $group = CustomerGroupQuery::create()->filterByIsDefault(1)->findOne();
        self::assertInstanceOf(CustomerGroup::class, $group);

        return $group;
    }

    private function deny(CustomerGroup $group, Product $product): void
    {
        (new CatalogRestriction())
            ->setMode(CatalogRestrictionMode::Deny->value)
            ->setCustomerGroupId($group->getId())
            ->setProductId($product->getId())
            ->save($this->getPropelConnection());
        $this->getService('services_resetter')->reset();
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

    private function product(Category $category, string $title, string $url): Product
    {
        $product = $this->factory->product($category, $this->factory->taxRule(), $this->factory->currency(), ['basePrice' => 10.0, 'baseQuantity' => 5]);
        $product->setLocale('en_US')->setTitle($title)->save($this->getPropelConnection());
        $product->setRewrittenUrl('en_US', $url);

        return $product;
    }
}
