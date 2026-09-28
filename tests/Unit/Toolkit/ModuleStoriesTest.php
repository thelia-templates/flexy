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

namespace FlexyBundle\Tests\Unit\Toolkit;

use FlexyBundle\Toolkit\ComponentStatus;
use FlexyBundle\Toolkit\ModuleStories;
use FlexyBundle\Toolkit\Story;
use FlexyBundle\Toolkit\StoryProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * The stories a module hangs in the toolkit come out shaped like the theme's own, so the
 * controller can list both from one array.
 */
final class ModuleStoriesTest extends TestCase
{
    public function testStoriesAreGroupedByCategoryInTheShapeOfTheThemeOnes(): void
    {
        $grouped = (new ModuleStories([
            $this->provider(
                $this->story('Modules', 'Flexy extension demo / Callout', ComponentStatus::READY),
                $this->story('Molecules', 'Rating'),
            ),
        ]))->grouped();

        self::assertSame(['Modules', 'Molecules'], array_keys($grouped));
        self::assertSame([
            [
                'twigPath' => '@FlexyExtensionDemoModule/toolkit/Callout.html.twig',
                'path' => __FILE__,
                'name' => 'Flexy extension demo / Callout',
                'slug' => 'modules-flexy-extension-demo-callout',
                'status' => ComponentStatus::READY,
            ],
        ], $grouped['Modules']);
        self::assertSame('molecules-rating', $grouped['Molecules'][0]['slug']);
        self::assertNull($grouped['Molecules'][0]['status']);
    }

    public function testAHiddenStoryIsLeftOut(): void
    {
        $grouped = (new ModuleStories([
            $this->provider($this->story('Modules', 'Gone', ComponentStatus::HIDDEN)),
        ]))->grouped();

        self::assertSame([], $grouped);
    }

    public function testTheProvidersKeepTheirOrder(): void
    {
        $grouped = (new ModuleStories([
            $this->provider($this->story('Modules', 'First')),
            $this->provider($this->story('Modules', 'Second')),
        ]))->grouped();

        self::assertSame(['First', 'Second'], array_column($grouped['Modules'], 'name'));
    }

    public function testTwoStoriesOnOneSlugStopThePage(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('share the toolkit slug "modules-same"');

        (new ModuleStories([
            $this->provider($this->story('Modules', 'Same'), $this->story('modules', 'same')),
        ]))->grouped();
    }

    public function testAStoryNeedsANamespacedTemplateAndAReadableSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be namespaced');

        new Story('Modules', 'Callout', 'toolkit/Callout.html.twig', __FILE__);
    }

    public function testAStoryRefusesAnUnknownStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown status "done"');

        new Story('Modules', 'Callout', '@FlexyExtensionDemoModule/toolkit/Callout.html.twig', __FILE__, 'done');
    }

    private function story(string $category, string $name, ?string $status = null): Story
    {
        return new Story($category, $name, '@FlexyExtensionDemoModule/toolkit/Callout.html.twig', __FILE__, $status);
    }

    private function provider(Story ...$stories): StoryProviderInterface
    {
        return new class($stories) implements StoryProviderInterface {
            /** @param list<Story> $stories */
            public function __construct(private readonly array $stories)
            {
            }

            public function stories(): array
            {
                return $this->stories;
            }
        };
    }
}
