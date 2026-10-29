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

namespace FlexyBundle\Tests\Unit\Service;

use FlexyBundle\Service\BundleLines;
use PHPUnit\Framework\TestCase;
use Thelia\Domain\Checkout\DTO\CheckoutViolation;

/**
 * The theme is built against `thelia/thelia@main`, which has no `CheckoutProgressionService::firstViolation()` until
 * the bundles land: asking for it unguarded would take the whole checkout down.
 */
final class BundleFirstViolationGuardTest extends TestCase
{
    public function testAProgressionThatKnowsTheVerdictAnswersIt(): void
    {
        $violation = new CheckoutViolation('cart', 'bundle-line-not-orderable', 'refused');
        $progression = new class($violation) {
            public function __construct(private readonly CheckoutViolation $violation)
            {
            }

            public function firstViolation(object $cart): CheckoutViolation
            {
                return $this->violation;
            }
        };

        self::assertSame($violation, BundleLines::firstViolationOf($progression, new \stdClass()));
    }

    public function testAProgressionOfACoreThatPredatesTheVerdictAnswersNothing(): void
    {
        $progression = new class {
            public function forget(): void
            {
            }
        };

        self::assertNull(BundleLines::firstViolationOf($progression, new \stdClass()));
    }
}
