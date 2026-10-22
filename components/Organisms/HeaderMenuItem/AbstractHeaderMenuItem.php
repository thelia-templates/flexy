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

namespace FlexyBundle\Components\Organisms\HeaderMenuItem;

use Thelia\Api\Service\DataAccess\DataAccessService;

abstract class AbstractHeaderMenuItem
{
    /** False when the shop does not serve the entity: the entry is left out of the header. */
    public bool $served = true;

    public function __construct(
        protected readonly DataAccessService $dataAccessService,
    ) {
    }

    /**
     * Resolves title/publicUrl via resources() only if not already provided.
     *
     * Null when the shop does not serve the entity: resources() hands back null for an item the
     * front API answers 404 for (a hidden category, a folder that is not published), and the
     * header leaves such an entry out rather than draw it without a title or a link.
     *
     * @return array{0: string, 1: string}|null [title, href]
     */
    protected function resolveTitleAndHref(string $endpoint, int|string|null $id, ?string $title, ?string $href): ?array
    {
        if ($title !== null && $href !== null) {
            return [$title, $href];
        }

        if ($id === null) {
            return [$title ?? '', $href ?? ''];
        }

        $entity = $this->dataAccessService->resources($endpoint.'/'.$id);

        if (null === $entity) {
            return null;
        }

        return [
            $title ?? ($entity['i18ns']['title'] ?? ''),
            $href ?? ($entity['publicUrl'] ?? ''),
        ];
    }

    /**
     * Unique navigation key for the mobile drill-down (data-menu-sub) — prefixed by the
     * concrete class name, since Category and Folder ids live in separate spaces and can collide.
     */
    protected function buildMenuKey(int|string|null $id): string
    {
        $parts = explode('\\', static::class);

        return strtolower(end($parts)).'-'.$id;
    }

    /**
     * Groups branches with children into columns, others into leafLinks.
     *
     * Branches arrive with their children already attached — NavigationTree owns the traversal,
     * so this no longer fetches anything.
     *
     * @param array<int, array<string, mixed>> $branches
     * @param array<int, array<string, mixed>> $extraLeaves
     *
     * @return array{columns: array<int, array{branch: array, children: array}>, leafLinks: array<int, array>}
     */
    protected function buildMegaMenu(array $branches, array $extraLeaves = []): array
    {
        $columns = [];
        $leafLinks = [];

        foreach ($branches as $branch) {
            $children = $branch['children'] ?? [];

            if (\count($children) > 0) {
                $columns[] = ['branch' => $branch, 'children' => $children];
            } else {
                $leafLinks[] = $branch;
            }
        }

        return [
            'columns' => $columns,
            'leafLinks' => array_merge($leafLinks, $extraLeaves),
        ];
    }
}
