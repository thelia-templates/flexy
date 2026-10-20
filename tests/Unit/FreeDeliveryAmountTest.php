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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The core has no free delivery threshold the theme could read, so the free delivery
 * reassurance says no amount: a figure written in a template is a promise the shop never made.
 */
final class FreeDeliveryAmountTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function reinsurances(): iterable
    {
        foreach ([
            'components/Molecules/Reinsurance/toolkit.html.twig',
            'components/Layouts/ReinsuranceModule/toolkit.html.twig',
            'components/Layouts/ProductDetails/Base.html.twig',
            'product.html.twig',
        ] as $template) {
            yield $template => [$template];
        }
    }

    #[DataProvider('reinsurances')]
    public function testNoAmountIsWrittenInTheFreeDeliveryReassurance(string $template): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/'.$template);

        self::assertDoesNotMatchRegularExpression('/\d\s*€|€\s*\d/u', $source);
    }
}
