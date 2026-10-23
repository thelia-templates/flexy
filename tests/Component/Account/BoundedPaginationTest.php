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

namespace FlexyBundle\Tests\Component\Account;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\Flexy\CustomerSessionInjector;

/**
 * The listings page through what the query string asks for. Whatever it asks, the page
 * answers: a number too large for the API to offset used to end in a 500.
 */
final class BoundedPaginationTest extends WebIntegrationTestCase
{
    private FixtureFactory $factory;

    private CustomerSessionInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();

        // Not createFixtureFactory(): it leaves a request on the stack, in front of the ones the client sends.
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->injector = new CustomerSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pagesThatAreNotUsable(): iterable
    {
        yield 'past what an integer holds' => ['page=99999999999999999999'];
        yield 'the largest integer' => ['page=9223372036854775807'];
        yield 'text' => ['page=abc'];
        yield 'negative' => ['page=-4'];
        yield 'a list' => ['page[]=1'];
    }

    #[DataProvider('pagesThatAreNotUsable')]
    public function testTheOrdersOfTheAccountAnswerWhateverThePage(string $query): void
    {
        $this->injector->setCustomer($this->factory->customer($this->factory->customerTitle()));

        $this->client->request('GET', '/account/orders?'.$query);

        self::assertResponseIsSuccessful();
    }

    #[DataProvider('pagesThatAreNotUsable')]
    public function testTheContentsOfAFolderAnswerWhateverThePage(string $query): void
    {
        $folder = $this->factory->folder();
        $this->factory->content($folder, ['title' => 'Only content']);

        $crawler = $this->client->request('GET', '/?view=folder&folder_id='.$folder->getId().'&'.$query);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Only content', $crawler->text());
    }

    #[DataProvider('pagesThatAreNotUsable')]
    public function testTheProductsOfACategoryAnswerWhateverThePage(string $query): void
    {
        $category = $this->factory->category();
        $category->setLocale('en_US')->setTitle('Shelf')->save($this->getPropelConnection());
        $this->factory->product($category, $this->factory->taxRule(), $this->factory->currency(), ['title' => 'Only product']);

        $crawler = $this->client->request('GET', '/?view=category&category_id='.$category->getId().'&'.$query);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Only product', $crawler->text());
    }

    public function testAPagePastTheLastOneOfAFolderIsTheLastOne(): void
    {
        $folder = $this->factory->folder();

        for ($i = 1; $i <= 13; ++$i) {
            $this->factory->content($folder, ['title' => \sprintf('Content n%02d', $i)]);
        }

        $crawler = $this->client->request('GET', '/?view=folder&folder_id='.$folder->getId().'&page=9223372036854775807');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.Pagination [aria-current="page"]'));
        self::assertSame('2', trim($crawler->filter('.Pagination [aria-current="page"]')->text()));
    }
}
