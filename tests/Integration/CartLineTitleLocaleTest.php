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

namespace FlexyBundle\Tests\Integration;

use FlexyBundle\Components\Organisms\Cart\Base as Cart;
use FlexyBundle\Components\Organisms\CartItem\Base as CartItem;
use FlexyBundle\DTO\CartItemDto;
use FlexyBundle\Service\CartStockService;
use FlexyBundle\Service\ProductSaleElementsService;
use Thelia\Api\Service\DataAccess\AttributeAccessService;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Cart\Service\CartGiftWrappingService;
use Thelia\Domain\Cart\Service\CartItemService;
use Thelia\Domain\Cart\Service\CartRetriever;
use Thelia\Domain\Cart\Service\CartSelectionService;
use Thelia\Domain\Checkout\Service\GiftWrappingProvider;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Shipping\Service\PostageHandler;
use Thelia\Domain\Taxation\TaxEngine\TaxCalculatorFactoryInterface;
use Thelia\Domain\Taxation\TaxEngine\TaxEngine;
use Thelia\Model\Cart as CartModel;
use Thelia\Model\CartItem as CartItemModel;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * A line of the cart shows the title and the chapo of its product in the language of the shop, not in the locale the product
 * model happens to be loaded with. Runs on an installed shop (bin/test-prepare).
 */
final class CartLineTitleLocaleTest extends IntegrationTestCase
{
    private CartModel $cartModel;
    private CartItemModel $cartItemModel;

    protected function setUp(): void
    {
        parent::setUp();

        $factory = $this->createFixtureFactory();
        // Pricing the line needs a default currency, which an installed shop has and a bare test database may not.
        $currency = CurrencyQuery::create()->findOneByByDefault(1) ?? $factory->currency(['byDefault' => 1]);
        $product = $factory->product($factory->category(), $factory->taxRule(), $currency, [
            'title' => 'Helmet',
            'locale' => 'en_US',
        ]);
        $product->setLocale('en_US')->setChapo('English chapo')->save();
        $product->setLocale('de_DE')->setTitle('Helm')->setChapo('Deutscher Chapo')->save();
        // Back to the locale the model is loaded with by default, the one the line used to be read in.
        $product->setLocale('en_US');

        $this->cartModel = $factory->cart(null, ['currency' => $currency]);
        $this->cartItemModel = $factory->cartItem($this->cartModel, $product);
    }

    public function testTheTitleAndTheChapoOfALineAreReadInTheLanguageOfTheShop(): void
    {
        $item = $this->cartItem('de_DE');
        $item->mount($this->lineOf($this->cartItemModel));

        self::assertSame('Helm', $item->title);
        self::assertSame('Deutscher Chapo', $item->desc);
    }

    public function testAnotherLanguageOfTheShopReadsItsOwnTitle(): void
    {
        $item = $this->cartItem('en_US');
        $item->mount($this->lineOf($this->cartItemModel));

        self::assertSame('Helmet', $item->title);
        self::assertSame('English chapo', $item->desc);
    }

    public function testTheCartTitlesItsLinesInTheLanguageOfTheShop(): void
    {
        $cart = $this->cart('de_DE');
        $cart->fetchCart();

        self::assertCount(1, $cart->items);
        self::assertSame('Helm', $cart->items[0]->title);
    }

    public function testAProductWithoutTranslationFallsBackToTheDefaultLanguageOfTheShop(): void
    {
        // The rule of the core (CartItem::getProduct) falls back to the default language when the setting is 1, its
        // default: with no row for it the core reads the integer 1 and applies it.
        ConfigQuery::create()->filterByName('default_lang_without_translation')->delete();
        ConfigQuery::resetCache();
        $factory = $this->createFixtureFactory();
        $currency = CurrencyQuery::create()->findOneByByDefault(1);
        $untranslated = $factory->product($factory->category(), $factory->taxRule(), $currency, ['title' => 'Gloves', 'locale' => 'en_US']);
        $cartModel = $factory->cart(null, ['currency' => $currency]);
        $line = $factory->cartItem($cartModel, $untranslated);

        try {
            $item = $this->cartItem('de_DE');
            $item->mount($this->lineOf($line));
            $cart = $this->cart('de_DE', $cartModel);
            $cart->fetchCart();
        } finally {
            ConfigQuery::write('default_lang_without_translation', '1');
        }

        self::assertSame('Gloves', $item->title);
        self::assertSame('Gloves', $cart->items[0]->title);
    }

    private function lineOf(CartItemModel $model): CartItemDto
    {
        return CartItemDto::fromArray([
            'id' => $model->getId(),
            'cartId' => $model->getCartId(),
            'productId' => $model->getProductId(),
            'productSaleElementsId' => $model->getProductSaleElementsId(),
            'quantity' => 1,
            'stock' => 10,
            'stockManaged' => false,
            'isOffered' => false,
            'title' => '',
        ]);
    }

    private function langService(string $locale): LangService
    {
        $langService = self::createStub(LangService::class);
        $langService->method('getLocale')->willReturn($locale);

        return $langService;
    }

    private function cartItem(string $locale): CartItem
    {
        return new CartItem(
            $this->getService(ProductSaleElementsService::class),
            $this->getService(TaxEngine::class),
            $this->langService($locale),
            new CartStockService(),
        );
    }

    private function cart(string $locale, ?CartModel $cartModel = null): Cart
    {
        $cartRetriever = self::createStub(CartRetriever::class);
        $cartRetriever->method('fromSession')->willReturn($cartModel ?? $this->cartModel);

        return new Cart(
            new CartFacade(
                self::createStub(CartItemService::class),
                self::createStub(CartSelectionService::class),
                self::createStub(PostageHandler::class),
                $cartRetriever,
                new CartGiftWrappingService(new GiftWrappingProvider(self::createStub(TaxCalculatorFactoryInterface::class))),
            ),
            self::createStub(FormServiceInterface::class),
            new CartStockService(),
            self::createStub(AttributeAccessService::class),
            $this->langService($locale),
        );
    }
}
