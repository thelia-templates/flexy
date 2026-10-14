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

namespace FlexyBundle\Tests\Unit\Service;

use FlexyBundle\Service\NavigationTree;
use PHPUnit\Framework\TestCase;
use Thelia\Api\Service\DataAccess\DataAccessService;

/**
 * The header lists the categories in the order set in the back-office, at every depth.
 */
final class NavigationTreeTest extends TestCase
{
    private const CATEGORIES = [
        ['id' => 1, 'parent' => 0, 'position' => 2, 'title' => 'Shoes'],
        ['id' => 2, 'parent' => 0, 'position' => 1, 'title' => 'Bags'],
        ['id' => 10, 'parent' => 1, 'position' => 2, 'title' => 'Boots'],
        ['id' => 11, 'parent' => 1, 'position' => 1, 'title' => 'Sneakers'],
        ['id' => 12, 'parent' => 2, 'position' => 1, 'title' => 'Totes'],
        ['id' => 20, 'parent' => 10, 'position' => 2, 'title' => 'Ankle boots'],
        ['id' => 21, 'parent' => 10, 'position' => 1, 'title' => 'Knee boots'],
    ];

    public function testCategoriesFollowTheirPositionAtEveryDepth(): void
    {
        $tree = new NavigationTree($this->categoriesApi());

        self::assertSame(['Bags', 'Shoes'], array_column($tree->categoryRoots(), 'title'));

        $shoes = $tree->categoryBranches(1);
        self::assertSame(['Sneakers', 'Boots'], array_column($shoes, 'title'));
        self::assertSame(['Knee boots', 'Ankle boots'], array_column($shoes[1]['children'], 'title'));
    }

    /**
     * Answers like the categories endpoint: rows come by id unless the request asks for the
     * position order.
     */
    private function categoriesApi(): DataAccessService
    {
        $api = self::createStub(DataAccessService::class);
        $api->method('resources')->willReturnCallback(static function (string $path, array $parameters): array {
            $parents = (array) $parameters['parent'];
            $rows = array_values(array_filter(
                self::CATEGORIES,
                static fn (array $category): bool => \in_array($category['parent'], $parents, true),
            ));

            $sortKey = ($parameters['order[position]'] ?? null) === 'asc' ? 'position' : 'id';
            usort($rows, static fn (array $a, array $b): int => $a[$sortKey] <=> $b[$sortKey]);

            return array_map(
                static fn (array $category): array => [
                    'id' => $category['id'],
                    'parent' => $category['parent'],
                    'i18ns' => ['title' => $category['title']],
                    'publicUrl' => '/'.$category['id'],
                ],
                $rows,
            );
        });

        return $api;
    }
}
