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

namespace FlexyBundle\EventListener;

use Symfony\Component\AssetMapper\Event\PreAssetsCompileEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfonycasts\TailwindBundle\TailwindBuilder;

/**
 * Rebuilds the Tailwind stylesheet minified before AssetMapper compiles the assets.
 *
 * `tailwind:build` writes an unminified file and the bundle has no setting to change that, so
 * `asset-map:compile` would publish (and precompress) the readable version. Debug keeps the
 * readable build, which is the one a developer inspects.
 *
 * A shop whose front template ships no stylesheet has nothing to build: the compilation goes on.
 *
 * Registered by FlexyBundle only when symfonycasts/tailwind-bundle is installed.
 */
final readonly class TailwindMinifiedBuildListener
{
    public function __construct(
        /** @var \Closure(): TailwindBuilder */
        private \Closure $tailwindBuilder,
        private bool $debug,
    ) {
    }

    public function __invoke(PreAssetsCompileEvent $event): void
    {
        if ($this->debug) {
            return;
        }

        $io = new SymfonyStyle(new ArrayInput([]), $event->getOutput());

        try {
            $tailwindBuilder = ($this->tailwindBuilder)();
        } catch (\InvalidArgumentException) {
            // The builder checks its input files when it is built: a stylesheet that is not there
            // makes it refuse to exist.
            $io->writeln('No Tailwind stylesheet to build.');

            return;
        }

        if ([] === $tailwindBuilder->getInputCssPaths()) {
            $io->writeln('No Tailwind stylesheet to build.');

            return;
        }

        $tailwindBuilder->setOutput($io);

        $process = $tailwindBuilder->runBuild(watch: false, poll: false, minify: true);
        $process->wait(static function (string $type, string $buffer) use ($io): void {
            $io->write($buffer);
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(\sprintf('Error building the minified Tailwind stylesheet: "%s"', $process->getErrorOutput()));
        }
    }
}
