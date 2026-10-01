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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\ComponentRendererInterface;

/**
 * On a phone the mega-menu is a drill-down: the arrow of a column asks the header controller
 * for the sub-list whose data-menu-sub matches its key, and the controller opens the first
 * match. Each column must therefore open its own list.
 */
final class HeaderMenuItemRenderTest extends KernelTestCase
{
    public function testEachColumnArrowOpensItsOwnSubList(): void
    {
        $crawler = $this->render([
            'menuKey' => 'category-3',
            'title' => 'Living room',
            'href' => '/living-room',
            'columns' => [
                $this->column(10, 'Sofas', ['Corner sofas', 'Sofa beds']),
                $this->column(11, 'Tables', ['Coffee tables']),
                $this->column(12, 'Lighting', ['Floor lamps']),
            ],
        ]);

        $columns = $crawler->filter('.Submenu-column:not(.Submenu-column--links)');
        self::assertCount(3, $columns);

        $keys = $columns->each(function (Crawler $column): string {
            $subKey = $column->filter('.Submenu-sub')->attr('data-menu-sub');

            self::assertSame(
                $subKey,
                $this->itemParam($column->filter('button.HeaderMenuItem-back')),
                'The arrow of a column targets the sub-list of that column.',
            );
            self::assertSame('category-3', $column->filter('.Submenu-sub')->attr('data-menu-previous'));

            return $subKey;
        });

        self::assertSame($keys, array_unique($keys), 'Every column has its own key.');
        self::assertNotContains('category-3', $keys, 'A column key cannot be the key of the menu itself.');
    }

    public function testTwoMenusDoNotShareColumnKeys(): void
    {
        $first = $this->render(['menuKey' => 'category-3', 'title' => 'A', 'href' => '/a', 'columns' => [$this->column(10, 'A1', ['A11'])]]);
        $second = $this->render(['menuKey' => 'folder-3', 'title' => 'B', 'href' => '/b', 'columns' => [$this->column(10, 'B1', ['B11'])]]);

        self::assertNotSame(
            $first->filter('.Submenu-sub')->attr('data-menu-sub'),
            $second->filter('.Submenu-sub')->attr('data-menu-sub'),
        );
    }

    /**
     * @param list<string> $children
     *
     * @return array{branch: array<string, mixed>, children: list<array<string, mixed>>}
     */
    private function column(int $id, string $title, array $children): array
    {
        return [
            'branch' => ['id' => $id, 'title' => $title, 'href' => '/'.strtolower($title)],
            'children' => array_map(
                static fn (string $child): array => ['id' => 0, 'title' => $child, 'href' => '/'.strtolower($child)],
                $children,
            ),
        ];
    }

    private function itemParam(Crawler $button): ?string
    {
        foreach ($button->getNode(0)?->attributes ?? [] as $attribute) {
            if (str_ends_with($attribute->name, '-item-param')) {
                return $attribute->value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $props */
    private function render(array $props): Crawler
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return new Crawler($renderer->createAndRender('Organisms:HeaderMenuItem:Base', $props));
    }
}
