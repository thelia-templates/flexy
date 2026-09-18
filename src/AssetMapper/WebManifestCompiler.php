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

namespace FlexyBundle\AssetMapper;

use Psr\Log\LoggerInterface;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\Compiler\AssetCompilerInterface;
use Symfony\Component\AssetMapper\MappedAsset;
use Symfony\Component\Filesystem\Path;

/**
 * Rewrites the asset paths a web app manifest points at, the way the bundle already does for
 * the url() of a stylesheet and the imports of a module. A browser resolves those paths
 * against the manifest's own digested URL, where the undigested names do not exist.
 */
final class WebManifestCompiler implements AssetCompilerInterface
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function supports(MappedAsset $asset): bool
    {
        return 'webmanifest' === $asset->publicExtension;
    }

    public function compile(string $content, MappedAsset $asset, AssetMapperInterface $assetMapper): string
    {
        try {
            $manifest = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Not this compiler's job to reject a malformed manifest.
            $this->warn(\sprintf('Unable to parse "%s": ', $asset->sourcePath) . $e->getMessage());

            return $content;
        }

        if (!\is_array($manifest)) {
            return $content;
        }

        $rewrite = fn (string $src): string => $this->resolve($src, $asset, $assetMapper);

        // The three places the specification puts an asset path.
        foreach (['icons', 'screenshots'] as $key) {
            if ($this->isRewritableList($manifest, $key, $asset)) {
                $manifest[$key] = $this->rewriteSrcList($manifest[$key], $rewrite);
            }
        }

        if ($this->isRewritableList($manifest, 'shortcuts', $asset)) {
            foreach ($manifest['shortcuts'] as $index => $shortcut) {
                if (\is_array($shortcut) && $this->isRewritableList($shortcut, 'icons', $asset)) {
                    $manifest['shortcuts'][$index]['icons'] = $this->rewriteSrcList($shortcut['icons'], $rewrite);
                }
            }
        }

        return json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * Absent is fine; present but not a list is a mistake worth a word, and the key is left as
     * it is rather than dropped from the compiled manifest.
     *
     * @param array<array-key, mixed> $manifest
     */
    private function isRewritableList(array $manifest, string $key, MappedAsset $asset): bool
    {
        if (!\array_key_exists($key, $manifest)) {
            return false;
        }

        if (!\is_array($manifest[$key])) {
            $this->warn(\sprintf('"%s" in "%s" is not a list and was left untouched.', $key, $asset->sourcePath));

            return false;
        }

        return true;
    }

    /**
     * @param array<array-key, mixed>  $entries
     * @param callable(string): string $rewrite
     *
     * @return array<array-key, mixed>
     */
    private function rewriteSrcList(array $entries, callable $rewrite): array
    {
        foreach ($entries as $index => $entry) {
            if (\is_array($entry) && \is_string($entry['src'] ?? null)) {
                $entries[$index]['src'] = $rewrite($entry['src']);
            }
        }

        return $entries;
    }

    private function resolve(string $src, MappedAsset $asset, AssetMapperInterface $assetMapper): string
    {
        // Already addressable on its own: absolute, protocol-relative, inline or a fragment.
        if (preg_match('{^(?:[a-z][a-z0-9+.-]*:|//|/|#)}i', $src)) {
            return $src;
        }

        $resolvedSourcePath = Path::join(\dirname($asset->sourcePath), $src);
        $dependentAsset = $assetMapper->getAssetFromSourcePath($resolvedSourcePath);

        if (null === $dependentAsset) {
            $message = \sprintf('Unable to find asset "%s" referenced in "%s". The file "%s" ', $src, $asset->sourcePath, $resolvedSourcePath);
            $message .= is_file($resolvedSourcePath)
                ? 'exists, but it is not in a mapped asset path. Add it to the "paths" config.'
                : 'does not exist.';

            $this->warn($message);

            return $src;
        }

        $asset->addDependency($dependentAsset);

        return Path::makeRelative($dependentAsset->publicPath, \dirname($asset->publicPathWithoutDigest));
    }

    private function warn(string $message): void
    {
        $this->logger?->warning($message);
    }
}
