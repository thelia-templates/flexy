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

namespace FlexyBundle\Service;

use Symfony\Contracts\Service\ResetInterface;
use Thelia\Api\Service\DataAccess\DataAccessService;

/**
 * Single point of passage for the header navigation trees.
 *
 * The header used to fetch one level per component, which meant one API call per node: the
 * roots in Layouts/Header, then the branches and their children in each HeaderMenuItem. This
 * owns the whole traversal instead, so the fetching strategy can change without touching a
 * template or a component — the categories side already fetches one call per depth rather
 * than one per node.
 *
 * Nodes are normalised to {id, title, href, children}: consumers no longer depend on the
 * shape of an API payload, which is what makes an alternative source possible later.
 *
 * The header renders three levels — roots, their branches, and the children listed under each
 * branch column — so nothing deeper is ever fetched.
 */
class NavigationTree implements ResetInterface
{
    /** The back-office order: without it, the API returns categories by id. */
    private const CATEGORY_ORDER = ['order[position]' => 'asc'];

    /** @var array<int, array<string, mixed>>|null */
    private ?array $categoryTree = null;

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $folderBranches = [];

    public function __construct(
        private readonly DataAccessService $dataAccessService,
    ) {
    }

    /**
     * Top-level categories, without their children: the header row only needs a label and a link.
     *
     * @return array<int, array<string, mixed>>
     */
    public function categoryRoots(): array
    {
        return $this->categoryTree();
    }

    /**
     * Branches under one root, each already carrying its own children.
     *
     * @return array<int, array<string, mixed>>
     */
    public function categoryBranches(int|string $rootId): array
    {
        foreach ($this->categoryTree() as $root) {
            if ($root['id'] === (int) $rootId) {
                return $root['children'];
            }
        }

        return [];
    }

    /**
     * Branches under one folder, each already carrying its own children.
     *
     * @return array<int, array<string, mixed>>
     */
    public function folderBranches(int|string $rootId, bool $includeContents = false): array
    {
        $key = $rootId.'|'.($includeContents ? '1' : '0');

        return $this->folderBranches[$key] ??= $this->buildFolderBranches((int) $rootId, $includeContents);
    }

    /**
     * Contents filed directly under one folder, rendered as leaf links beside the branches.
     *
     * @return array<int, array<string, mixed>>
     */
    public function folderContents(int|string $folderId): array
    {
        return $this->normalise($this->fetch('/api/front/contents', [
            'contentFolders.folder.id' => $folderId,
            'visible' => true,
        ]));
    }

    public function reset(): void
    {
        $this->categoryTree = null;
        $this->folderBranches = [];
    }

    /**
     * One call per depth instead of one per node: the categories endpoint accepts a list of
     * parents, and each row carries its own parent, so a level can be fetched in a single
     * request and split afterwards. Relative order within a parent is preserved.
     *
     * @return array<int, array<string, mixed>>
     */
    private function categoryTree(): array
    {
        if ($this->categoryTree !== null) {
            return $this->categoryTree;
        }

        $roots = $this->normalise($this->fetch('/api/front/categories', [
            'parent' => 0,
            'visible' => true,
        ] + self::CATEGORY_ORDER));

        if ($roots === []) {
            return $this->categoryTree = [];
        }

        $branchesByRoot = $this->childrenByParent('/api/front/categories', ['parent' => array_column($roots, 'id')] + self::CATEGORY_ORDER);
        $branchIds = array_merge(...array_values(array_map(
            static fn (array $branches): array => array_column($branches, 'id'),
            $branchesByRoot,
        ))) ?: [];

        $leavesByBranch = $branchIds === [] ? [] : $this->childrenByParent('/api/front/categories', ['parent' => $branchIds] + self::CATEGORY_ORDER);

        foreach ($roots as &$root) {
            $root['children'] = $branchesByRoot[$root['id']] ?? [];

            foreach ($root['children'] as &$branch) {
                $branch['children'] = $leavesByBranch[$branch['id']] ?? [];
            }
            unset($branch);
        }
        unset($root);

        return $this->categoryTree = $roots;
    }

    /**
     * One request for a whole depth: the endpoint accepts a list of parents and each row
     * carries its own, so the level is split back per parent here.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function childrenByParent(string $path, array $parameters): array
    {
        return $this->groupBy(
            $this->fetch($path, $parameters + ['visible' => true]),
            static fn (array $row): int => (int) ($row['parent'] ?? 0),
        );
    }

    /**
     * Splits one grouped response back per parent, preserving the order rows came in.
     *
     * @param array<int, array<string, mixed>>      $rows
     * @param callable(array<string, mixed>): int   $parentOf
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function groupBy(array $rows, callable $parentOf): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$parentOf($row)][] = $row;
        }

        return array_map($this->normalise(...), $grouped);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFolderBranches(int $rootId, bool $includeContents): array
    {
        $branches = $this->normalise($this->fetch('/api/front/folders', [
            'parent' => $rootId,
            'visible' => true,
        ]));

        if ($branches === []) {
            return [];
        }

        $branchIds = array_column($branches, 'id');
        $foldersByParent = $this->childrenByParent('/api/front/folders', ['parent' => $branchIds]);
        $contentsByFolder = $includeContents
            ? $this->groupBy(
                $this->fetch('/api/front/contents', [
                    'contentFolders.folder.id' => $branchIds,
                    'visible' => true,
                ]),
                static fn (array $row): int => (int) ($row['contentFolders'][0]['folder']['id'] ?? 0),
            )
            : [];

        foreach ($branches as &$branch) {
            $branch['children'] = array_merge(
                $foldersByParent[$branch['id']] ?? [],
                $contentsByFolder[$branch['id']] ?? [],
            );
        }
        unset($branch);

        return $branches;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetch(string $path, array $parameters): array
    {
        $result = $this->dataAccessService->resources($path, $parameters);

        return \is_array($result) ? $result : [];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalise(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'title' => $row['i18ns']['title'] ?? '',
                'href' => $row['publicUrl'] ?? '',
                'children' => [],
            ],
            $rows,
        );
    }
}
