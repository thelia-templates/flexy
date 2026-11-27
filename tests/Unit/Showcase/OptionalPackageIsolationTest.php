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

namespace FlexyBundle\Tests\Unit\Showcase;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * The shop loads the routes of a template by reflecting on every class under `src/` (and the
 * components under `components/`): a class that extends or implements a type of the optional
 * showcase package fails there, with a fatal error, wherever the package is not installed. The
 * adapter lives in `showcase/`, which nothing scans.
 */
final class OptionalPackageIsolationTest extends TestCase
{
    private const PACKAGE_NAMESPACE = 'OpenStudio\\UxShowcaseBundle';

    /** Names the package only through `::class` and `interface_exists`, which load nothing. */
    private const ALLOWED = ['src/FlexyBundle.php'];

    public function testNoClassOfTheScannedDirectoriesNamesATypeOfTheOptionalPackage(): void
    {
        $root = \dirname(__DIR__, 3);
        $offenders = [];

        foreach ((new Finder())->files()->name('*.php')->in([$root.'/src', $root.'/components']) as $file) {
            $relative = substr($file->getRealPath(), \strlen($root) + 1);

            if (\in_array($relative, self::ALLOWED, true)) {
                continue;
            }

            if (str_contains($file->getContents(), self::PACKAGE_NAMESPACE)) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'Move these classes to showcase/: the shop reflects on every class of src/ and components/.');
    }
}
