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

use FlexyBundle\Service\BundleSelectionService;
use FlexyBundle\Tests\Support\BuildsBundles;
use Propel\Runtime\Propel;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Catalog\Bundle\BundleCompositionService;
use Thelia\Domain\Catalog\Bundle\BundlePriceMode;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * What the choices of a shopper on a bundle sheet come to, asked of the core: the composition it accepts and
 * the price it would write on the cart line, taxed by the rule of the product of the bundle. The theme adds no
 * rule of its own, which is what these figures pin: the plan's example, 600 struck and 540, taxed at 20 %.
 * Runs on an installed shop (bin/test-prepare).
 */
final class BundleSelectionServiceTest extends IntegrationTestCase
{
    use BuildsBundles;

    public function testTheDefaultCompositionIsPricedWithoutTheLock(): void
    {
        $example = $this->example();

        $composed = $this->service()->compose((int) $example['bundle']->getId(), $this->rows($example), []);

        self::assertFalse($composed['fellBack']);
        self::assertSame('0', $composed['composition']->quantityOf((int) $example['lock']->getId()));
        $quote = $this->service()->quote((int) $example['bundle']->getId(), $this->saleElementsIdOf($example['bundle']), $composed['composition']);

        // 560 struck, 504 after the 10 %, then the 20 % of the product of the bundle.
        self::assertEqualsWithDelta(604.80, $quote['price'], 0.001);
        self::assertEqualsWithDelta(672.0, $quote['originalPrice'], 0.001);
        self::assertEqualsWithDelta(67.20, $quote['saving'], 0.001);
    }

    public function testTheDiscountOfTheSignedInCustomerIsTheOneOfTheQuoteOfTheCore(): void
    {
        $example = $this->example();
        $factory = new FixtureFactory(Propel::getConnection('TheliaMain'));
        $customer = $factory->customer($factory->customerTitle());
        $customer->setDiscount('10')->save();
        $session = static::getContainer()->get('request_stack')->getCurrentRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);
        $session->setCustomerUser($customer);

        $composed = $this->service()->compose((int) $example['bundle']->getId(), $this->rows($example), []);
        $quote = $this->service()->quote((int) $example['bundle']->getId(), $this->saleElementsIdOf($example['bundle']), $composed['composition']);

        // 504 less the 10 % of the customer is 453.60, 544.32 with the 20 % tax: the figures the core quotes, none of the theme's.
        self::assertEqualsWithDelta(544.32, $quote['price'], 0.01);
        // The struck price carries the discount of the customer too, as the core quotes it (672 less 10 %).
        self::assertEqualsWithDelta(604.80, $quote['originalPrice'], 0.01);
    }

    public function testAChangeOfTheSelectionChangesThePrice(): void
    {
        $example = $this->example();

        $composed = $this->service()->compose((int) $example['bundle']->getId(), $this->rows($example), [(int) $example['lock']->getId() => '2']);

        self::assertFalse($composed['fellBack']);
        self::assertSame('2', $composed['composition']->quantityOf((int) $example['lock']->getId()));
        $quote = $this->service()->quote((int) $example['bundle']->getId(), $this->saleElementsIdOf($example['bundle']), $composed['composition']);

        // The plan: 600 struck, 540, which is 720 and 648 with the 20 % of the product of the bundle.
        self::assertEqualsWithDelta(648.0, $quote['price'], 0.001);
        self::assertEqualsWithDelta(720.0, $quote['originalPrice'], 0.001);
        self::assertEqualsWithDelta(72.0, $quote['saving'], 0.001);
    }

    public function testAQuantityTheCoreRefusesFallsBackToTheDefaultComposition(): void
    {
        $example = $this->example();

        // The lock is bounded to 3.
        $composed = $this->service()->compose((int) $example['bundle']->getId(), $this->rows($example), [(int) $example['lock']->getId() => '4']);

        self::assertTrue($composed['fellBack']);
        self::assertNotNull($composed['refusal']);
        self::assertSame('0', $composed['composition']->quantityOf((int) $example['lock']->getId()), 'the default composition stands in');
    }

    public function testWhatTheBrowserSendsBackIsOnlyReadForTheComponentsOfTheSheet(): void
    {
        $example = $this->example();

        $composed = $this->service()->compose((int) $example['bundle']->getId(), $this->rows($example), [
            99999999 => '5',
            (int) $example['lock']->getId() => 'many',
        ]);

        self::assertFalse($composed['fellBack'], 'an unknown item and a non numeric quantity are not read, they do not refuse the sheet');
        self::assertSame('0', $composed['composition']->quantityOf((int) $example['lock']->getId()));
    }

    public function testTheDefaultQuantitiesAreTheOnesOfTheDefaultCompositionNotTheOnesOfTheItems(): void
    {
        $example = $this->example();

        // The bike (1) and the helmets (2) are in the default composition, the lock is an item of quantity 1 left out by default.
        self::assertSame(
            [(int) $example['bike']->getId() => '1', (int) $example['helmet']->getId() => '2', (int) $example['lock']->getId() => '0'],
            $this->service()->defaultQuantities((int) $example['bundle']->getId(), $this->rows($example)),
        );
    }

    public function testAFieldThatHoldsItsDefaultQuantityIsNotAChange(): void
    {
        $example = $this->example();
        $rows = $this->rows($example);

        $composed = $this->service()->compose((int) $example['bundle']->getId(), $rows, $this->service()->defaultQuantities((int) $example['bundle']->getId(), $rows));

        self::assertFalse($composed['fellBack']);
        self::assertSame('0', $composed['composition']->quantityOf((int) $example['lock']->getId()));
    }

    public function testABundleWhoseRequiredComponentIsGoneIsNotSellable(): void
    {
        $example = $this->example();
        $example['bikeProduct']->setVisible(0)->save();

        $composed = $this->service()->compose((int) $example['bundle']->getId(), $this->rows($example), []);

        self::assertNull($composed['composition']);
    }

    public function testABundleInFixedModeIsPricedByItsOwnSaleElement(): void
    {
        $example = $this->example(BundlePriceMode::Fixed);
        $composed = $this->service()->compose((int) $example['bundle']->getId(), $this->rows($example), []);

        self::assertNull($this->service()->quote((int) $example['bundle']->getId(), $this->saleElementsIdOf($example['bundle']), $composed['composition']));
    }

    /**
     * @return array{bundle: \Thelia\Model\Product, bike: \Thelia\Model\ProductBundleItem, helmet: \Thelia\Model\ProductBundleItem, lock: \Thelia\Model\ProductBundleItem, bikeProduct: \Thelia\Model\Product, helmetProduct: \Thelia\Model\Product, lockProduct: \Thelia\Model\Product}
     */
    private function example(BundlePriceMode $mode = BundlePriceMode::Computed): array
    {
        return $this->exampleBundle(new FixtureFactory(Propel::getConnection('TheliaMain')), $this->getService(BundleCompositionService::class), $mode);
    }

    /**
     * @param array<string, mixed> $example
     *
     * @return list<array{itemId: int, quantity: string}>
     */
    private function rows(array $example): array
    {
        return array_map(static fn (string $item): array => ['itemId' => (int) $example[$item]->getId(), 'quantity' => '1'], ['bike', 'helmet', 'lock']);
    }

    private function service(): BundleSelectionService
    {
        return $this->getService(BundleSelectionService::class);
    }
}
