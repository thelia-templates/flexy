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

namespace FlexyBundle\Tests\Support;

use Propel\Runtime\Propel;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Catalog\Stock\StockSettings;
use Thelia\Model\Area;
use Thelia\Model\AreaDeliveryModule;
use Thelia\Model\Cart;
use Thelia\Model\CartItem;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CountryArea;
use Thelia\Model\Customer;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Product;
use Thelia\Module\BaseModule;
use Thelia\Test\FixtureFactory;
use Thelia\Tests\Support\Module\SessionCartCarrierModule;
use Thelia\Tests\Support\Stock\TestCallerTransaction;

/**
 * A shop that reserves its stock, for the tests of the theme that hold the stock of a cart: carts of one unit of a product
 * of a given stock, the session of a buyer, and the moment until which a line is held.
 *
 * For a class that extends IntegrationTestCase. The transaction of a test is no caller's: a hold runs in it once the test
 * has said so, which the core declares in its test support (skipped when the core installed does not have it).
 */
trait HoldsStock
{
    private ?FixtureFactory $fixtures = null;

    private function startHoldingStock(): void
    {
        if (!class_exists(TestCallerTransaction::class)) {
            self::markTestSkipped('The test support of the core that declares the transaction of a test is not installed.');
        }

        ConfigQuery::write('check-available-stock', '1');
        ConfigQuery::write('allow_negative_stock', '0');
        $this->reserve(true);
        $this->getService(TestCallerTransaction::class)->ignoreTheLevelsOpenOn(Propel::getWriteConnection('TheliaMain'));
    }

    private function stopHoldingStock(): void
    {
        ConfigQuery::resetCache();

        if (class_exists(TestCallerTransaction::class)) {
            $this->getService(TestCallerTransaction::class)->reset();
        }
    }

    private function reserve(bool $enabled): void
    {
        ConfigQuery::write(StockSettings::RESERVATION_ENABLED, $enabled ? '1' : '0');
        ConfigQuery::write(StockSettings::RESERVATION_SCOPE, 'checkout');
        ConfigQuery::write(StockSettings::RESERVATION_DURATION, '15');
        ConfigQuery::resetCache();
    }

    /**
     * @param array{0: Cart, 1: CartItem} $cartAndLine
     *
     * @return array{0: Cart, 1: CartItem}
     */
    private function inTheSession(array $cartAndLine, ?Customer $customer = null): array
    {
        $session = $this->session();
        $session->setCustomerUser($customer ?? $this->customer());
        $session->setSessionCart($cartAndLine[0]);

        return $cartAndLine;
    }

    private function customer(): Customer
    {
        $fixtures = $this->fixtures();

        return $fixtures->customer($fixtures->customerTitle());
    }

    /**
     * @param bool $virtual a product to download, which no hold concerns
     */
    private function product(int $quantity, bool $virtual = false): Product
    {
        $fixtures = $this->fixtures();
        $product = $fixtures->product($fixtures->category(), $fixtures->taxRule(), $fixtures->currency(), ['basePrice' => 10.0, 'baseQuantity' => $quantity]);

        if ($virtual) {
            $product->setVirtual(1)->save(Propel::getConnection('TheliaMain'));
        }

        return $product;
    }

    /**
     * The delivery and the payment of the cart settled, with a carrier that serves any cart that weighs something: the steps of
     * the checkout are reachable and the order placeable.
     */
    private function readyToOrder(Cart $cart, Customer $customer): void
    {
        if (!class_exists(SessionCartCarrierModule::class)) {
            self::markTestSkipped('The test carrier of the core is not installed.');
        }

        $fixtures = $this->fixtures();
        $connection = Propel::getConnection('TheliaMain');
        $country = $fixtures->country();
        $address = $fixtures->address($customer, $country);

        $code = 'HoldCarrier';
        $delivery = (new Module())->setCode($code)->setType(BaseModule::DELIVERY_MODULE_TYPE)->setActivate(BaseModule::IS_ACTIVATED)->setFullNamespace(SessionCartCarrierModule::class);
        $delivery->save($connection);
        $carrier = new SessionCartCarrierModule($code, (int) $fixtures->taxRule()->getId());
        $carrier->setContainer(static::getContainer());
        static::getContainer()->set('module.'.$code, $carrier);

        // The country of the address is in an area the carrier serves.
        $area = (new Area())->setName('Where the carrier goes');
        $area->save($connection);
        (new CountryArea())->setCountryId($country->getId())->setAreaId($area->getId())->save($connection);
        (new AreaDeliveryModule())->setAreaId($area->getId())->setDeliveryModuleId($delivery->getId())->save($connection);

        // The carrier serves a cart that weighs something.
        $connection->exec('UPDATE product_sale_elements SET weight = 1 WHERE id IN (SELECT product_sale_elements_id FROM cart_item WHERE cart_id = '.(int) $cart->getId().')');

        $payment = ModuleQuery::create()->findOneByCode('Cheque') ?? self::fail('No Cheque module: run bin/test-prepare.');

        $cart
            ->setAddressDeliveryId($fixtures->cartAddress($address, $country)->getId())
            ->setAddressInvoiceId($fixtures->cartAddress($address, $country)->getId())
            ->setDeliveryModuleId($delivery->getId())
            ->setPaymentModuleId($payment->getId())
            ->setPostage('5')
            ->setPostageTax('0')
            ->save($connection);
    }

    /**
     * A cart holding one unit of the product.
     *
     * @return array{0: Cart, 1: CartItem}
     */
    private function cartWith(Product $product, ?Customer $customer = null): array
    {
        $fixtures = $this->fixtures();
        $cart = $fixtures->cart($customer, ['currency' => $fixtures->currency()]);
        $line = $fixtures->cartItem($cart, $product, null, ['quantity' => 1.0]);

        return [$cart, $line];
    }

    private function reservedUntilOf(CartItem $line): ?string
    {
        $value = Propel::getConnection('TheliaMain')->query('SELECT reserved_until FROM cart_item WHERE id = '.(int) $line->getId())->fetchColumn();

        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function session(): Session
    {
        $session = static::getContainer()->get('request_stack')->getMainRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);

        return $session;
    }

    private function fixtures(): FixtureFactory
    {
        return $this->fixtures ??= $this->createFixtureFactory();
    }
}
