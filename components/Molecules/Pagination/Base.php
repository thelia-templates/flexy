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

namespace FlexyBundle\Components\Molecules\Pagination;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
class Base
{
    /** Pages offered on each side of the current one, on top of the first and the last. */
    private const WINDOW = 1;

    public int $totalItems = 0;

    public int $itemsPerPage = 0;

    /** Visitor input, through the caller's query string: bounded at both ends below. */
    public int $currentPage = 1;

    /** The query string page links are appended to, opening with `?`, or null. */
    public ?string $baseUrl = null;

    public function getTotalPages(): int
    {
        if ($this->itemsPerPage < 1 || $this->totalItems < 1) {
            return 0;
        }

        return (int) ceil($this->totalItems / $this->itemsPerPage);
    }

    public function getCurrent(): int
    {
        $totalPages = $this->getTotalPages();

        if ($totalPages < 1) {
            return 1;
        }

        return max(1, min($this->currentPage, $totalPages));
    }

    /**
     * The pages to offer, in order, each saying whether pages were skipped just before it.
     *
     * @return list<array{page: int, gap: bool}>
     */
    public function getTrail(): array
    {
        $totalPages = $this->getTotalPages();

        if ($totalPages < 1) {
            return [];
        }

        $current = $this->getCurrent();

        $pages = array_filter(
            [1, $current - self::WINDOW, $current, $current + self::WINDOW, $totalPages],
            static fn (int $page): bool => $page >= 1 && $page <= $totalPages,
        );

        $pages = array_unique($pages);
        sort($pages);

        $trail = [];
        $previous = null;

        foreach ($pages as $page) {
            // A gap stands for more than one skipped page.
            $trail[] = ['page' => $page, 'gap' => null !== $previous && $page - $previous > 1];
            $previous = $page;
        }

        return $trail;
    }

    public function url(int $page): string
    {
        return ($this->baseUrl ? $this->baseUrl . '&' : '?') . 'page=' . $page;
    }
}
