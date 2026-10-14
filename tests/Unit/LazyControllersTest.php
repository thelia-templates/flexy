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
use Symfony\Component\Finder\Finder;

/**
 * Which controllers StimulusBundle puts in the start-up graph of every page. A controller without the
 * `stimulusFetch: 'lazy'` comment is imported by the generated controllers module and preloaded on every page,
 * along with what it imports; only the controllers of the header, rendered on every page, need that.
 */
final class LazyControllersTest extends TestCase
{
    private const THEME = __DIR__.'/../..';

    /** The header and what it opens, on every page: registered up front. */
    private const EAGER = [
        'live-refresh-on-focus',
        'sticky-observer',
        'Layouts--Header--base',
        'Molecules--MobileDrawer--base',
        'Molecules--MobileDrawer--open',
        'Organisms--HeaderMenuItem--base',
        'Organisms--HeaderProfile--base',
        'Organisms--LangSelect--base',
    ];

    /** The test of StimulusBundle (ControllersMapGenerator::loadCustomControllers()). */
    private const LAZY_COMMENT = "/\\/\\*\\s*stimulusFetch:\\s*'lazy'\\s*\\*\\//i";

    /** @return iterable<string, array{string, string}> */
    public static function controllers(): iterable
    {
        foreach (['assets/controllers', 'components'] as $directory) {
            $finder = (new Finder())->in(self::THEME.'/'.$directory)->files()->name('/_controller\.js$/')->sortByName();

            foreach ($finder as $file) {
                // The identifier StimulusBundle registers: the path below the directory, `_controller.js` dropped.
                $name = str_replace(['_', '/'], ['-', '--'], substr($file->getRelativePathname(), 0, -\strlen('_controller.js')));

                yield $name => [$name, $file->getRealPath()];
            }
        }
    }

    #[DataProvider('controllers')]
    public function testOnlyTheControllersOfTheHeaderLoadUpFront(string $name, string $path): void
    {
        $eager = \in_array($name, self::EAGER, true);
        $lazy = 1 === preg_match(self::LAZY_COMMENT, (string) file_get_contents($path));

        self::assertSame(!$eager, $lazy, $eager
            ? \sprintf('"%s" is rendered on every page: keep it in the start-up graph', $name)
            : \sprintf('"%s" serves some pages only: add `/* stimulusFetch: \'lazy\' */` before its class', $name));
    }

    public function testTheControllersLoadedUpFrontStillExist(): void
    {
        self::assertSame([], array_values(array_diff(self::EAGER, array_keys(iterator_to_array(self::controllers())))));
    }
}
