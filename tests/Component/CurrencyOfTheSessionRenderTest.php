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

use FlexyBundle\Components\Organisms\CurrencySelect\Base as CurrencySelect;
use FlexyBundle\Twig\CurrencyExtension;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Currency;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * Prices follow the currency the visitor browses in: the amounts the API converts are written
 * with the sign of that currency, not with a euro sign, and the header offers the choice
 * only when there is one.
 */
final class CurrencyOfTheSessionRenderTest extends IntegrationTestCase
{
    private Session $session;

    /** A product the tax engine can find: a card prices its taxes off the product itself. */
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $fixtures = new FixtureFactory($this->getPropelConnection());
        $this->productId = $fixtures->product($fixtures->category(), $fixtures->taxRule(), $fixtures->currency())->getId();

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        // The base class leaves a bare request on the stack, which would be the main one: this test is about the request pushed below.
        while (null !== $requestStack->pop()) {
        }
        $request = Request::create('https://localhost/sofas.html?page=2');
        $this->session = new Session(new MockArraySessionStorage());
        $request->setSession($this->session);
        $requestStack->push($request);

        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');
    }

    #[IgnoreDeprecations]
    public function testAProductCardIsPricedInTheCurrencyOfTheSession(): void
    {
        $this->session->setCurrency((new Currency())->setCode('USD'));

        $html = $this->productCard();

        self::assertMatchesRegularExpression('/ProductCard-price"><span dir="ltr">\$/', $html);
        self::assertStringNotContainsString('€', $html);
    }

    #[IgnoreDeprecations]
    public function testWithoutACurrencyInTheSessionTheDefaultOneIsUsed(): void
    {
        $code = Currency::getDefaultCurrency()->getCode();

        self::assertSame($code, $this->extension()->currentCurrencyCode());
    }

    /**
     * @param list<array<string, mixed>> $currencies
     */
    #[DataProvider('currencySets')]
    public function testTheSelectorIsOfferedOnlyWhenThereIsAChoice(array $currencies, bool $offered): void
    {
        $dataAccess = $this->createMock(DataAccessService::class);
        $dataAccess->method('resources')->willReturn($currencies);
        $this->session->setCurrency((new Currency())->setCode('USD'));

        $select = new CurrencySelect($dataAccess, $this->extension(), self::getContainer()->get('request_stack'));

        self::assertSame($offered, null !== $select->getCurrentCurrency());
        self::assertSame($offered ? 'USD' : null, $select->getCurrentCurrency()['code'] ?? null);
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>, bool}>
     */
    public static function currencySets(): iterable
    {
        $euro = ['code' => 'EUR', 'symbol' => '€'];
        $dollar = ['code' => 'USD', 'symbol' => '$'];

        yield 'none' => [[], false];
        yield 'one' => [[$dollar], false];
        yield 'two' => [[$euro, $dollar], true];
    }

    public function testTheSwitchLinkKeepsThePageAndItsQueryString(): void
    {
        $dataAccess = $this->createMock(DataAccessService::class);
        $dataAccess->method('resources')->willReturn([['code' => 'EUR'], ['code' => 'USD']]);

        $select = new CurrencySelect($dataAccess, $this->extension(), self::getContainer()->get('request_stack'));

        self::assertSame(
            ['EUR' => '/sofas.html?page=2&currency=EUR', 'USD' => '/sofas.html?page=2&currency=USD'],
            $select->getSwitchUrls(),
        );
    }

    private function extension(): CurrencyExtension
    {
        return self::getContainer()->get(CurrencyExtension::class);
    }

    private function productCard(): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender('Organisms:ProductCard:Base', ['product' => [
            'id' => $this->productId,
            'ref' => 'REF-'.$this->productId,
            'visible' => true,
            'position' => 1,
            'virtual' => false,
            'publicUrl' => '/organic-cotton-t-shirt',
            'i18ns' => ['title' => 'Organic cotton t-shirt'],
            'ProductColor' => ['colors' => []],
            'productCategories' => [],
            'productSaleElements' => [[
                'id' => 1,
                'isDefault' => true,
                'promo' => false,
                'newness' => false,
                'productPrices' => [['price' => 25.0, 'promoPrice' => 20.0, 'taxedPrice' => 30.0, 'taxedPromoPrice' => 24.0, 'currency' => 'USD']],
                'attributeCombinations' => [],
            ]],
        ]]);
    }
}
