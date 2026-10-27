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

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\Category;
use Thelia\Model\CustomerGroup;
use Thelia\Model\Product;
use Thelia\Model\ProductCategory;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\Flexy\CustomerSessionInjector;

/**
 * A customer group can carry a white list of categories. Its customers browse the listed
 * categories and their sub-trees; the ancestors of a listed category stay reachable as passing
 * categories (their page opens, the products filed only there are not shown); every other
 * category is closed: out of the menu and the lists, and its address answers the 404 of the
 * theme. A product is on sale when it has a category that is open, or none. A group whose list
 * is empty, the default group of a visitor included, sees the whole catalog.
 */
final class RestrictedCatalogPagesTest extends WebIntegrationTestCase
{
    private const ANCESTOR_URL = 'flexy-restricted-ancestor.html';

    private const LISTED_URL = 'flexy-restricted-listed.html';

    private const SUB_URL = 'flexy-restricted-listed-sub.html';

    private const OTHER_URL = 'flexy-restricted-other-category.html';

    private const ONLY_IN_OTHER_URL = 'flexy-restricted-only-in-other.html';

    private const ALSO_IN_LISTED_URL = 'flexy-restricted-also-in-listed.html';

    private FixtureFactory $factory;

    private CustomerSessionInjector $injector;

    private CustomerGroup $pro;

    protected function setUp(): void
    {
        parent::setUp();

        // Not createFixtureFactory(): it leaves a request on the stack, in front of the ones the client sends.
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->injector = new CustomerSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $ancestor = $this->category('Restricted ancestor', self::ANCESTOR_URL);
        $listed = $this->category('Restricted listed category', self::LISTED_URL, $ancestor);
        $this->category('Restricted listed sub category', self::SUB_URL, $listed);
        $other = $this->category('Restricted other category', self::OTHER_URL, $ancestor);

        // Only the listed category is open to the group: the default group, which a visitor belongs to, has no list.
        $this->pro = $this->factory->customerGroup('pro', 0.0, true, false, null, [], [$listed->getId()]);

        $this->product('Only in the other category', self::ONLY_IN_OTHER_URL, $other);

        $alsoListed = $this->product('Also in the listed category', self::ALSO_IN_LISTED_URL, $other);
        $link = (new ProductCategory())->setProductId($alsoListed->getId())->setCategoryId($listed->getId())->setDefaultCategory(0)->setPosition(1);
        $link->save($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);

        parent::tearDown();
    }

    public function testACategoryOutOfTheWhiteListAnswersTheNotFoundPageToAMemberOfTheGroup(): void
    {
        $this->signInAsProCustomer();

        $this->client->request('GET', '/'.self::OTHER_URL);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheListedCategoryIsServedToAMemberOfTheGroup(): void
    {
        $this->signInAsProCustomer();

        $this->assertPageRenders('/'.self::LISTED_URL);

        self::assertStringContainsString('Restricted listed category', (string) $this->client->getResponse()->getContent());
    }

    public function testASubCategoryOfTheListedCategoryIsServedToAMemberOfTheGroup(): void
    {
        $this->signInAsProCustomer();

        $this->assertPageRenders('/'.self::SUB_URL);

        self::assertStringContainsString('Restricted listed sub category', (string) $this->client->getResponse()->getContent());
    }

    public function testTheAncestorOfTheListedCategoryIsServedAsAPassingCategory(): void
    {
        $this->signInAsProCustomer();

        $this->assertPageRenders('/'.self::ANCESTOR_URL);
        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Restricted listed category', $content);
        self::assertStringNotContainsString('Restricted other category', $content);
    }

    public function testAProductOfACategoryOutOfTheWhiteListAloneAnswersTheNotFoundPage(): void
    {
        $this->signInAsProCustomer();

        $this->client->request('GET', '/'.self::ONLY_IN_OTHER_URL);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAProductThatHasAListedCategoryAmongOthersIsServedToAMemberOfTheGroup(): void
    {
        $this->signInAsProCustomer();

        $this->assertPageRenders('/'.self::ALSO_IN_LISTED_URL);

        self::assertStringContainsString('Also in the listed category', (string) $this->client->getResponse()->getContent());
    }

    public function testTheMenuOfTheHomePageLeavesTheCategoriesOutOfTheWhiteListOutForAMemberOfTheGroup(): void
    {
        $this->signInAsProCustomer();

        $this->assertPageRenders('/');
        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Restricted listed category', $content);
        self::assertStringNotContainsString('Restricted other category', $content);
    }

    public function testAVisitorOfTheDefaultGroupWithoutListSeesTheWholeCatalog(): void
    {
        foreach ([self::ANCESTOR_URL, self::LISTED_URL, self::SUB_URL, self::OTHER_URL, self::ONLY_IN_OTHER_URL, self::ALSO_IN_LISTED_URL] as $url) {
            $this->assertPageRenders('/'.$url);
        }
    }

    public function testTheMenuOfTheHomePageOffersEveryCategoryToAVisitorOfTheDefaultGroup(): void
    {
        $this->assertPageRenders('/');
        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Restricted listed category', $content);
        self::assertStringContainsString('Restricted other category', $content);
    }

    public function testTheCategoryPageListsEverySubCategoryToAVisitorOfTheDefaultGroup(): void
    {
        $this->assertPageRenders('/'.self::ANCESTOR_URL);
        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Restricted listed category', $content);
        self::assertStringContainsString('Restricted other category', $content);
    }

    private function signInAsProCustomer(): void
    {
        $this->injector->setCustomer($this->factory->customer($this->factory->customerTitle(), ['customerGroup' => $this->pro]));
    }

    private function category(string $title, string $url, ?Category $parent = null): Category
    {
        $category = $this->factory->category(null === $parent ? [] : ['parent' => $parent->getId()]);
        $category->setLocale('en_US')->setTitle($title)->save($this->getPropelConnection());
        $category->setRewrittenUrl('en_US', $url);

        return $category;
    }

    private function product(string $title, string $url, Category $category): Product
    {
        $product = $this->factory->product($category, $this->factory->taxRule(), $this->factory->currency(), ['basePrice' => 10.0, 'baseQuantity' => 5]);
        $product->setLocale('en_US')->setTitle($title)->save($this->getPropelConnection());
        $product->setRewrittenUrl('en_US', $url);

        return $product;
    }
}
