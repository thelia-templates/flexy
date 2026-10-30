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

/**
 * The cart removes a line whatever the core is: a model of a core that predates the bundles has no `getBundleComposition()`,
 * and Propel answers an unknown method with an exception, which would make every removal of every line a 500.
 */
final class BundleStoredCompositionTest extends TestCase
{
    public function testALineOfACoreWithoutBundlesHasNoComposition(): void
    {
        $line = new class {
            public function getQuantity(): int
            {
                return 1;
            }
        };

        self::assertNull(BundleLines::storedComposition($line));
    }

    public function testALineOfAModelThatOnlyAnswersThroughMagicCallsHasNoComposition(): void
    {
        // The Propel behaviour: __call throws for any accessor the model does not generate.
        $line = new class {
            public function __call(string $name, array $arguments): never
            {
                throw new \BadMethodCallException('Call to undefined method: '.$name.'.');
            }
        };

        self::assertNull(BundleLines::storedComposition($line));
    }

    public function testTheCompositionOfABundleLineIsPassedThrough(): void
    {
        $line = new class {
            public function getBundleComposition(): string
            {
                return '[{"bundleItemId":1,"quantity":"2"}]';
            }
        };

        self::assertSame('[{"bundleItemId":1,"quantity":"2"}]', BundleLines::storedComposition($line));
    }

    public function testALineWithoutCompositionHasNone(): void
    {
        $line = new class {
            public function getBundleComposition(): ?string
            {
                return null;
            }
        };

        self::assertNull(BundleLines::storedComposition($line));
    }
}
