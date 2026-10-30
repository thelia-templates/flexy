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

namespace FlexyBundle\Tests\Unit;

use FlexyBundle\Service\BundleLines;
use FlexyBundle\Service\OrderLineType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The kinds of order line a bundle adds (the line of the pack, the lines of its components), read the way the core
 * reads them, from a payload that may predate the feature.
 */
final class OrderLineTypeTest extends TestCase
{
    /**
     * @param array<string, mixed> $line
     */
    #[DataProvider('lines')]
    public function testEachKindIsShippedOrNotAsTheCoreSaysIt(array $line, bool $shippable): void
    {
        self::assertSame($shippable, OrderLineType::isShippable($line));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function lines(): iterable
    {
        yield 'a product' => [['lineType' => 'product'], true];
        yield 'a line of an order placed before the feature' => [[], true];
        yield 'a service' => [['lineType' => 'service'], false];
        yield 'the line of a bundle: what it stands for leaves through its components' => [['lineType' => 'bundle'], false];
        yield 'a component' => [['lineType' => 'bundle_component', 'parent' => 10], true];
    }

    public function testComponentsAreGroupedUnderTheirBundleLineInTheOrderTheyWereStored(): void
    {
        $lines = [
            ['id' => 10, 'lineType' => 'bundle'],
            ['id' => 11, 'lineType' => 'bundle_component', 'parent' => 10],
            ['id' => 12, 'lineType' => 'bundle_component', 'parent' => 10],
            ['id' => 13, 'lineType' => 'product'],
            ['id' => 14, 'lineType' => 'bundle'],
            ['id' => 15, 'lineType' => 'bundle_component', 'parent' => 14],
        ];

        self::assertSame(
            [['index' => 0, 'components' => [1, 2]], ['index' => 3, 'components' => []], ['index' => 4, 'components' => [5]]],
            OrderLineType::groupUnderBundles($lines),
        );
    }

    public function testAComponentWhoseBundleLineIsNotThereIsKeptAsALineOfItsOwn(): void
    {
        $lines = [
            ['id' => 11, 'lineType' => 'bundle_component', 'parent' => 10],
            ['id' => 12, 'lineType' => 'bundle_component'],
            ['id' => 13, 'lineType' => 'product'],
        ];

        self::assertSame(
            [['index' => 0, 'components' => []], ['index' => 1, 'components' => []], ['index' => 2, 'components' => []]],
            OrderLineType::groupUnderBundles($lines),
            'an order is read as it was stored: nothing disappears',
        );
    }

    public function testTheQuantitiesOfAnOrderAreSaidForOneBundle(): void
    {
        // Three bundles bought: each of the two helmets a bundle holds is stored once per bundle, so six.
        $lines = BundleLines::fromOrderComponents(
            ['quantity' => 3],
            [['title' => 'Mountain bike', 'quantity' => 3], ['title' => 'Helmet', 'quantity' => 6], ['title' => 'Cable', 'quantity' => 1.5]],
        );

        self::assertSame(['1', '2', '0.5'], array_column($lines, 'quantity'));
        self::assertSame(['Mountain bike', 'Helmet', 'Cable'], array_column($lines, 'title'));
    }

    public function testTheComponentsOfACartLineKeepOnlyWhatThePagePrints(): void
    {
        $lines = BundleLines::fromCartComponents([
            ['bundleItemId' => 12, 'productSaleElementsId' => 77, 'productId' => 31, 'title' => 'Bike', 'quantity' => '1'],
            ['bundleItemId' => 13, 'title' => '  ', 'quantity' => '2'],
            'noise',
            ['title' => 'Helmet', 'quantity' => '2.500000'],
        ]);

        self::assertSame([['title' => 'Bike', 'quantity' => '1'], ['title' => 'Helmet', 'quantity' => '2.5']], $lines);
        self::assertSame([], BundleLines::fromCartComponents(null), 'a core that predates the bundles publishes nothing');
        self::assertSame([], BundleLines::fromCartComponents(''));
    }
}
