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
use Symfony\Component\Yaml\Yaml;

/** The message of a refused login reads as two sentences in every language: the second one ends with its full stop. */
final class LoginErrorMessageTest extends TestCase
{
    private const string KEY = 'Wrong email or password. Please try again';

    /**
     * @return iterable<string, array{string}>
     */
    public static function catalogs(): iterable
    {
        foreach (glob(\dirname(__DIR__, 2).'/translations/messages.*.yaml') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }

    #[DataProvider('catalogs')]
    public function testTheMessageEndsWithAFullStop(string $file): void
    {
        $catalog = Yaml::parseFile($file);
        if (!\is_array($catalog) || !isset($catalog[self::KEY])) {
            self::markTestSkipped('This catalog does not translate the message.');
        }

        self::assertStringEndsWith('.', $catalog[self::KEY]);
    }
}
