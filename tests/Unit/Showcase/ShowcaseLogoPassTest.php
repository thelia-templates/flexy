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

use FlexyBundle\Showcase\ShowcaseLogoPass;
use OpenStudio\UxShowcaseBundle\ShowcaseConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\ExpressionLanguage\Expression;

/**
 * The showcase wears the logo of the toolkit, whose URL is the one of the asset mapper, unless the
 * application chose its own.
 */
final class ShowcaseLogoPassTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(ShowcaseConfiguration::class)) {
            self::markTestSkipped('openstudio/ux-showcase-bundle is a suggestion of the theme and is not installed.');
        }
    }

    public function testTheLogoIsTheOneOfTheToolkitReadFromTheAssetMapper(): void
    {
        $builder = $this->builder();

        (new ShowcaseLogoPass())->process($builder);

        $logo = $builder->getDefinition(ShowcaseConfiguration::class)->getArgument(1);

        self::assertInstanceOf(Expression::class, $logo);
        self::assertSame("service('asset_mapper').getPublicPath('images/logo.png')", (string) $logo);
    }

    public function testTheLogoTheApplicationSetsIsKept(): void
    {
        $builder = $this->builder();
        $builder->prependExtensionConfig('ux_showcase', ['title' => 'Flexy']);
        $builder->loadFromExtension('ux_showcase', ['logo' => '/my-logo.svg']);

        (new ShowcaseLogoPass())->process($builder);

        self::assertNull($builder->getDefinition(ShowcaseConfiguration::class)->getArgument(1));
    }

    public function testNothingHappensWithoutTheShowcaseConfiguration(): void
    {
        $builder = new ContainerBuilder();

        (new ShowcaseLogoPass())->process($builder);

        self::assertFalse($builder->hasDefinition(ShowcaseConfiguration::class));
    }

    private function builder(): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'ux_showcase';
            }
        });
        $builder->setDefinition('asset_mapper', new Definition(\stdClass::class));
        $builder->setDefinition(ShowcaseConfiguration::class, (new Definition(ShowcaseConfiguration::class))->setArguments(['Showcase', null, [], []]));

        return $builder;
    }
}
