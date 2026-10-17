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

use PHPUnit\Framework\TestCase;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\CompiledAssetMapperConfigReader;
use Symfony\Component\AssetMapper\Compiler\JavaScriptImportPathCompiler;
use Symfony\Component\AssetMapper\ImportMap\ImportMapConfigReader;
use Symfony\Component\AssetMapper\ImportMap\ImportMapGenerator;
use Symfony\Component\AssetMapper\ImportMap\ImportMapRenderer;
use Symfony\Component\AssetMapper\ImportMap\RemotePackageStorage;
use Symfony\Component\AssetMapper\MappedAsset;
use Symfony\Component\Filesystem\Path;

/**
 * Turbo only works in the zones marked data-turbo="true", which only the checkout renders. The `importmap('app')`
 * tag of every page preloads each module app.js reaches through a static import; Turbo has to stay out of that
 * graph, or every page downloads it for nothing. This renders the tag with AssetMapper's own compiler, generator and
 * renderer over the theme's importmap.php and assets/: only the downloaded packages are stood in for.
 */
final class LazyTurboTest extends TestCase
{
    private const THEME = __DIR__.'/../..';

    private string $html;

    protected function setUp(): void
    {
        $theme = Path::canonicalize(self::THEME);
        $configReader = new ImportMapConfigReader($theme.'/importmap.php', new RemotePackageStorage($theme.'/assets/vendor'));
        $compiler = new JavaScriptImportPathCompiler($configReader);

        $assetMapper = new class($theme, $compiler) implements AssetMapperInterface {
            /** @var array<string, MappedAsset> */
            private array $assets = [];

            public function __construct(private readonly string $theme, private readonly JavaScriptImportPathCompiler $compiler)
            {
            }

            public function getAsset(string $logicalPath): ?MappedAsset
            {
                return $this->assets[$logicalPath] ?? null;
            }

            public function allAssets(): iterable
            {
                return $this->assets;
            }

            public function getAssetFromSourcePath(string $sourcePath): ?MappedAsset
            {
                $sourcePath = Path::canonicalize($sourcePath);
                $logicalPath = Path::isBasePath($this->theme.'/assets', $sourcePath)
                    ? Path::makeRelative($sourcePath, $this->theme.'/assets')
                    : 'vendor/'.basename($sourcePath);

                if (isset($this->assets[$logicalPath])) {
                    return $this->assets[$logicalPath];
                }

                $asset = new MappedAsset($logicalPath, $sourcePath, '/assets/'.$logicalPath, '/assets/'.$logicalPath, null, 'digest', false);
                $this->assets[$logicalPath] = $asset;

                // The theme's own scripts are compiled for real; a downloaded package is a leaf.
                if (str_ends_with($sourcePath, '.js') && is_file($sourcePath) && !str_starts_with($logicalPath, 'vendor/')) {
                    $this->compiler->compile((string) file_get_contents($sourcePath), $asset, $this);
                }

                return $asset;
            }

            public function getPublicPath(string $logicalPath): ?string
            {
                return $this->getAsset($logicalPath)?->publicPath;
            }
        };

        $generator = new ImportMapGenerator($assetMapper, new CompiledAssetMapperConfigReader($theme.'/var/no-compiled-importmap'), $configReader);
        $this->html = (new ImportMapRenderer($generator))->render('app');
    }

    public function testTurboIsNotPreloadedByThePageTag(): void
    {
        self::assertStringNotContainsString('rel="modulepreload" href="/assets/vendor/@hotwired/turbo', $this->html);
    }

    public function testTurboStaysResolvableForTheCheckout(): void
    {
        self::assertStringContainsString('"@hotwired/turbo": "/assets/vendor/', $this->html);
    }

    public function testTheModulesOfEveryPageAreStillPreloaded(): void
    {
        self::assertStringContainsString('rel="modulepreload" href="/assets/app.js"', $this->html);
        self::assertStringContainsString('rel="modulepreload" href="/assets/stimulus_bootstrap.js"', $this->html);
    }
}
