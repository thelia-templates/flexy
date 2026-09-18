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

namespace FlexyBundle\Tests\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\AssetMapper\AssetMapperInterface;

/** The manifest as the asset mapper actually compiles it. */
final class WebManifestCompilerTest extends KernelTestCase
{
    private function manifest(): array
    {
        /** @var AssetMapperInterface $assetMapper */
        $assetMapper = self::getContainer()->get('asset_mapper');

        $asset = $assetMapper->getAsset('favicons/site.webmanifest');

        self::assertNotNull($asset, 'the manifest is in the asset map');

        return json_decode($asset->content ?? (string) file_get_contents($asset->sourcePath), true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testEveryIconPathIsRewrittenToItsCompiledName(): void
    {
        $sources = array_column($this->manifest()['icons'], 'src');

        self::assertNotEmpty($sources, 'the manifest declares icons');

        foreach ($sources as $src) {
            self::assertMatchesRegularExpression(
                '/-[A-Za-z0-9_-]{7,}\.png$/',
                $src,
                \sprintf('"%s" carries the digest of the file actually served', $src)
            );
        }
    }

    /** Relative, so the file works whatever prefix the shop serves its assets under. */
    public function testTheRewrittenPathsStayRelative(): void
    {
        foreach (array_column($this->manifest()['icons'], 'src') as $src) {
            self::assertStringStartsNotWith('/', $src);
            self::assertStringStartsNotWith('http', $src);
        }
    }

    public function testTheRestOfTheManifestIsLeftAlone(): void
    {
        $manifest = $this->manifest();

        self::assertSame('standalone', $manifest['display']);
        self::assertArrayHasKey('name', $manifest);
        self::assertSame('192x192', $manifest['icons'][0]['sizes']);
        self::assertSame('maskable', $manifest['icons'][0]['purpose']);
    }
}
