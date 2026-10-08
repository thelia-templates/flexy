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

use FlexyBundle\Search\VisibleProductIds;
use Thelia\Model\ProductQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The ids a search engine returns are narrowed to the products a visitor may see, in the
 * engine's order, which the search pages by. Runs on an installed shop (bin/test-prepare); the
 * product hidden here is restored by the test transaction.
 */
final class VisibleProductIdsTest extends IntegrationTestCase
{
    public function testHiddenAndUnknownIdsAreLeftOutAndTheOrderIsKept(): void
    {
        $products = ProductQuery::create()->filterByVisible(1)->orderById()->limit(3)->find();

        if (\count($products) < 3) {
            self::markTestSkipped('The shop has fewer than three visible products.');
        }

        [$first, $second, $third] = [$products[0], $products[1], $products[2]];
        $second->setVisible(0)->save($this->getPropelConnection());

        /** @var VisibleProductIds $visibleProductIds */
        $visibleProductIds = $this->getService(VisibleProductIds::class);

        self::assertSame(
            [$third->getId(), $first->getId()],
            $visibleProductIds->filter([$third->getId(), \PHP_INT_MAX, $second->getId(), $first->getId()]),
        );
    }

    public function testNoIdsAskNothing(): void
    {
        /** @var VisibleProductIds $visibleProductIds */
        $visibleProductIds = $this->getService(VisibleProductIds::class);

        self::assertSame([], $visibleProductIds->filter([]));
    }
}
