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

use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Propel\Runtime\Propel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\ConfigQuery;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * A card tells a pre-order from the answer of the core: the `isPreorder` of the sale element, as
 * `/api/front/products` publishes it. The payload rendered here is a real response of that endpoint for a real
 * product, never a hand-written one, so the test follows the contract and not an assumption about it.
 */
final class ProductCardPreorderRenderTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        // The base class leaves a bare request on the stack, which would be the main one: this test is about the request pushed below.
        while (null !== $requestStack->pop()) {
        }
        $request = Request::create('https://localhost/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');
        ConfigQuery::write('check-available-stock', '1');
    }

    /**
     * The card of a product with one sale element, from the response of the front API.
     *
     * @param float|null $limit the pre-order limit of the sale mode that has one
     */
    private function renderCardOfAProduct(int $stock, int $policy, ?float $limit = null, bool $virtual = false): string
    {
        $fixtures = new FixtureFactory(Propel::getConnection('TheliaMain'));
        $product = $fixtures->product($fixtures->category(), $fixtures->taxRule(), $fixtures->currency(), ['title' => 'Organic cotton t-shirt', 'basePrice' => 25.0, 'baseQuantity' => $stock]);
        $product->setVirtual($virtual ? 1 : 0)->save();
        ProductSaleElementsQuery::create()->filterByProductId($product->getId())->update(['OutOfStockPolicy' => $policy, 'PreorderLimit' => $limit]);

        $response = $this->getService(DataAccessService::class)->resources('/api/front/products', ['id' => [$product->getId()], 'itemsPerPage' => 1]);
        self::assertIsArray($response);
        $payload = array_is_list($response) ? $response[0] : $response;
        self::assertSame($product->getId(), (int) $payload['id'], 'the product of the response of the front API');

        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender('Organisms:ProductCard:Base', ['product' => $payload]);
    }

    #[IgnoreDeprecations]
    public function testASaleModeWithoutLimitAtZeroStockIsAPreorder(): void
    {
        self::assertStringContainsString('Pre-order', $this->renderCardOfAProduct(0, 1));
    }

    #[IgnoreDeprecations]
    public function testALimitedSaleModeAtZeroStockIsAPreorder(): void
    {
        self::assertStringContainsString('Pre-order', $this->renderCardOfAProduct(0, 2, 10.0));
    }

    #[IgnoreDeprecations]
    public function testASaleModeThatRefusesAtZeroStockIsNoPreorder(): void
    {
        self::assertStringNotContainsString('Pre-order', $this->renderCardOfAProduct(0, 0));
    }

    #[IgnoreDeprecations]
    public function testALimitUsedUpIsNoPreorder(): void
    {
        self::assertStringNotContainsString('Pre-order', $this->renderCardOfAProduct(-10, 2, 10.0));
    }

    #[IgnoreDeprecations]
    public function testAProductInStockIsNoPreorder(): void
    {
        self::assertStringNotContainsString('Pre-order', $this->renderCardOfAProduct(4, 2, 10.0));
    }

    #[IgnoreDeprecations]
    public function testNothingIsSaidWhenTheShopDoesNotCheckTheStockOrTheProductIsVirtual(): void
    {
        self::assertStringNotContainsString('Pre-order', $this->renderCardOfAProduct(0, 1, virtual: true));

        ConfigQuery::write('check-available-stock', '0');
        self::assertStringNotContainsString('Pre-order', $this->renderCardOfAProduct(0, 1));
    }

    #[IgnoreDeprecations]
    public function testTheBadgeIsTheStockTagOfThePreorderAndNotTheWarningOfTheNewTag(): void
    {
        $html = $this->renderCardOfAProduct(0, 1);

        self::assertStringContainsString('StockTag--preorder', $html);
        self::assertStringNotContainsString('Tag--warning', $html);
    }
}
