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

/** The colon after the "Quantity" label belongs to the template and to the language, not to a translation key. */
final class QuantityLabelCatalogTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function catalogs(): iterable
    {
        foreach (glob(\dirname(__DIR__, 2).'/translations/messages.*.yaml') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }

    #[DataProvider('catalogs')]
    public function testNoKeyCarriesItsColon(string $file): void
    {
        $catalog = Yaml::parseFile($file);

        self::assertArrayNotHasKey('Quantity:', $catalog);
    }

    public function testTheTemplatesAskForTheLabelWithoutItsColon(): void
    {
        $root = \dirname(__DIR__, 2);
        $templates = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/components', \FilesystemIterator::SKIP_DOTS));

        foreach ([...$templates, new \SplFileInfo($root.'/account-return.html.twig')] as $file) {
            if (str_ends_with($file->getFilename(), '.twig')) {
                self::assertStringNotContainsString("'Quantity:'", (string) file_get_contents($file->getPathname()), $file->getPathname());
            }
        }
    }
}
