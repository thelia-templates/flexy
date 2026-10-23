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

use FlexyBundle\EventListener\TailwindMinifiedBuildListener;
use FlexyBundle\FlexyBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\AssetMapper\Event\PreAssetsCompileEvent;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\DirectoryLoader;
use Symfony\Component\DependencyInjection\Loader\GlobFileLoader;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Process\Process;
use Symfonycasts\TailwindBundle\TailwindBuilder;

/**
 * The assets AssetMapper compiles for production are minified and precompressed, and none of
 * this applies in dev, where they are served from their sources.
 */
final class PrecompressedAssetsTest extends TestCase
{
    private const EXTENSIONS_THE_BUNDLE_CONFIGURES = [
        'stimulus',
        'framework',
        'liip_imagine',
        'tales_from_a_dev_twig_extra_tailwind',
    ];

    public function testAssetsArePrecompressedInProd(): void
    {
        $builder = $this->prependFor('prod');

        self::assertTrue($this->precompressSettingOf($builder)['enabled'] ?? false);
    }

    public function testAssetsAreNotPrecompressedInDev(): void
    {
        $builder = $this->prependFor('dev');

        self::assertSame([], $this->precompressSettingOf($builder));
    }

    public function testTheFormatsAndExtensionsAreLeftToTheProject(): void
    {
        $setting = $this->precompressSettingOf($this->prependFor('prod'));

        self::assertArrayNotHasKey('formats', $setting);
        self::assertArrayNotHasKey('extensions', $setting);
    }

    public function testTheMinifiedBuildRunsBeforeTheCompilationWhenTailwindIsInstalled(): void
    {
        $builder = $this->loadFor('prod', withTailwind: true);

        self::assertTrue($builder->hasDefinition(TailwindMinifiedBuildListener::class));
        $definition = $builder->getDefinition(TailwindMinifiedBuildListener::class);
        self::assertSame(
            [['event' => PreAssetsCompileEvent::class]],
            $definition->getTag('kernel.event_listener'),
        );
        $builderArgument = $definition->getArgument(0);
        self::assertInstanceOf(ServiceClosureArgument::class, $builderArgument);
        self::assertSame('tailwind.builder', (string) $builderArgument->getValues()[0]);
    }

    public function testNoMinifiedBuildIsRegisteredWithoutTheTailwindBundle(): void
    {
        $builder = $this->loadFor('prod', withTailwind: false);

        // The class is declared as excluded from the services of src/, which leaves an inert
        // definition behind that the container drops: what must be absent is a listener.
        $tags = $builder->hasDefinition(TailwindMinifiedBuildListener::class)
            ? $builder->getDefinition(TailwindMinifiedBuildListener::class)->getTags()
            : [];

        self::assertArrayNotHasKey('kernel.event_listener', $tags);
    }

    public function testTheStylesheetIsBuiltMinifiedOutsideDebug(): void
    {
        $process = $this->createMock(Process::class);
        $process->expects(self::once())->method('wait');
        $process->method('isSuccessful')->willReturn(true);

        $tailwind = $this->createMock(TailwindBuilder::class);
        $tailwind->method('getInputCssPaths')->willReturn(['/app/assets/styles/app.css']);
        $tailwind->expects(self::once())
            ->method('runBuild')
            ->with(false, false, true)
            ->willReturn($process);

        (new TailwindMinifiedBuildListener(static fn (): TailwindBuilder => $tailwind, false))(new PreAssetsCompileEvent(new BufferedOutput()));
    }

    public function testTheStylesheetKeepsItsReadableBuildInDebug(): void
    {
        $tailwind = $this->createMock(TailwindBuilder::class);
        $tailwind->expects(self::never())->method('runBuild');

        (new TailwindMinifiedBuildListener(static fn (): TailwindBuilder => $tailwind, true))(new PreAssetsCompileEvent(new BufferedOutput()));
    }

    public function testNoBuildIsAttemptedWithoutAStylesheet(): void
    {
        $tailwind = $this->createMock(TailwindBuilder::class);
        $tailwind->method('getInputCssPaths')->willReturn([]);
        $tailwind->expects(self::never())->method('runBuild');
        $output = new BufferedOutput();

        (new TailwindMinifiedBuildListener(static fn (): TailwindBuilder => $tailwind, false))(new PreAssetsCompileEvent($output));

        self::assertStringContainsString('No Tailwind stylesheet', $output->fetch());
    }

    public function testNoBuildIsAttemptedWhenTheBuilderRefusesAMissingStylesheet(): void
    {
        // The builder checks its input files when it is constructed.
        $missing = static function (): TailwindBuilder {
            throw new \InvalidArgumentException('The input CSS file "app.css" does not exist.');
        };
        $output = new BufferedOutput();

        (new TailwindMinifiedBuildListener($missing, false))(new PreAssetsCompileEvent($output));

        self::assertStringContainsString('No Tailwind stylesheet', $output->fetch());
    }

    public function testAFailedBuildStopsTheCompilationWithTheErrorOfTheProcess(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getErrorOutput')->willReturn('unexpected token');

        $tailwind = $this->createMock(TailwindBuilder::class);
        $tailwind->method('getInputCssPaths')->willReturn(['/app/assets/styles/app.css']);
        $tailwind->method('runBuild')->willReturn($process);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unexpected token');

        (new TailwindMinifiedBuildListener(static fn (): TailwindBuilder => $tailwind, false))(new PreAssetsCompileEvent(new BufferedOutput()));
    }

    /**
     * @return array<string, mixed>
     */
    private function precompressSettingOf(ContainerBuilder $builder): array
    {
        $setting = [];

        foreach ($builder->getExtensionConfig('framework') as $config) {
            $setting = $config['asset_mapper']['precompress'] ?? $setting;
        }

        return $setting;
    }

    private function prependFor(string $environment): ContainerBuilder
    {
        $builder = $this->builderFor($environment);

        (new FlexyBundle())->prependExtension($this->configuratorFor($builder, $environment), $builder);

        return $builder;
    }

    private function loadFor(string $environment, bool $withTailwind): ContainerBuilder
    {
        $kernelContainer = $this->builderFor($environment);
        $kernelContainer->setParameter('kernel.bundles', $withTailwind
            ? ['FrameworkBundle' => FrameworkBundle::class, 'SymfonycastsTailwindBundle' => 'Symfonycasts\\TailwindBundle\\SymfonycastsTailwindBundle']
            : ['FrameworkBundle' => FrameworkBundle::class]);

        // The kernel loads an extension into a temporary container that holds this extension
        // alone and the parameters of the kernel: no other extension is registered there.
        // The builder is declared in the file of the pass: loading the pass makes it available.
        class_exists(MergeExtensionConfigurationPass::class);
        $builder = new MergeExtensionConfigurationContainerBuilder(
            (new FlexyBundle())->getContainerExtension(),
            new ParameterBag($kernelContainer->getParameterBag()->all()),
        );

        (new FlexyBundle())->loadExtension([], $this->configuratorFor($builder, $environment), $builder);

        return $builder;
    }

    private function builderFor(string $environment): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('thelia_front_template', 'flexy');
        $builder->setParameter('kernel.environment', $environment);
        $builder->setParameter('kernel.debug', 'prod' !== $environment);
        $builder->setParameter('kernel.bundles_metadata', [
            'FrameworkBundle' => [
                'path' => \dirname((string) (new \ReflectionClass(FrameworkBundle::class))->getFileName()),
            ],
        ]);

        foreach (self::EXTENSIONS_THE_BUNDLE_CONFIGURES as $alias) {
            $builder->registerExtension(new class($alias) extends Extension {
                public function __construct(private readonly string $alias)
                {
                }

                public function load(array $configs, ContainerBuilder $container): void
                {
                }

                public function getAlias(): string
                {
                    return $this->alias;
                }
            });
        }

        return $builder;
    }

    /**
     * The loaders know the environment, as the kernel's do: a `when@<env>` block is read for that
     * environment only.
     */
    private function configuratorFor(ContainerBuilder $builder, string $environment): ContainerConfigurator
    {
        $configDirectory = \dirname(__DIR__, 2).'/config';
        $fileLocator = new FileLocator($configDirectory);

        $phpLoader = new PhpFileLoader($builder, $fileLocator, $environment, null, true);
        $phpLoader->setResolver(new LoaderResolver([
            new YamlFileLoader($builder, $fileLocator, $environment, true),
            $phpLoader,
            new GlobFileLoader($builder, $fileLocator, $environment),
            new DirectoryLoader($builder, $fileLocator, $environment),
        ]));

        $instanceof = [];

        return new ContainerConfigurator(
            $builder,
            $phpLoader,
            $instanceof,
            $configDirectory.'/services.php',
            $configDirectory.'/services.php',
            $environment,
        );
    }
}
