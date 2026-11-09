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

/** The message of a refused login ends with its full stop, in the source text and in every catalog that translates it. */
final class LoginErrorMessageTest extends TestCase
{
    private const string KEY = 'Wrong email or password. Please try again.';

    public function testTheControllerAsksForTheKeyWithItsFullStop(): void
    {
        $controller = (string) file_get_contents(\dirname(__DIR__, 2).'/src/Controller/CustomerController.php');

        self::assertStringContainsString("trans('".self::KEY."')", $controller);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function translatingCatalogs(): iterable
    {
        foreach (['de_DE', 'en_US', 'fr_FR'] as $locale) {
            yield $locale => [\dirname(__DIR__, 2).'/translations/messages.'.$locale.'.yaml'];
        }
    }

    #[DataProvider('translatingCatalogs')]
    public function testTheCatalogTranslatesTheKeyWithAFullStop(string $file): void
    {
        $catalog = Yaml::parseFile($file);

        self::assertIsArray($catalog);
        self::assertArrayHasKey(self::KEY, $catalog);
        self::assertIsString($catalog[self::KEY]);
        self::assertStringEndsWith('.', $catalog[self::KEY]);
        self::assertArrayNotHasKey(rtrim(self::KEY, '.'), $catalog);
    }
}
