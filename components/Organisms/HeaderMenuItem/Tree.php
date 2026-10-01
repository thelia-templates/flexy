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

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Core\Content\Slot\ContentSlotLink;

/**
 * A header entry that brings its own children, as a CMS menu does: the same mega-menu as
 * a folder, its first-level children laid out the way a folder lays out its subfolders —
 * a child with children of its own is a column, the others are listed side by side.
 *
 * A child without an address is a heading, written as text rather than as a dead link.
 */
#[AsTwigComponent]
class Tree extends AbstractHeaderMenuItem
{
    public string $menuKey = '';
    public string $title = '';
    public string $href = '';
    public bool $opensInNewWindow = false;
    public array $columns = [];
    public array $leafLinks = [];

    public function mount(ContentSlotLink $link, int $position): void
    {
        $this->menuKey = $this->buildMenuKey($position);
        $this->title = $link->label;
        $this->href = $link->url ?? '';
        $this->opensInNewWindow = $link->opensInNewWindow;

        $result = $this->buildMegaMenu(array_map($this->branch(...), $link->children));

        $this->columns = $result['columns'];
        $this->leafLinks = $result['leafLinks'];
    }

    /**
     * @return array{title: string, href: string, opensInNewWindow: bool, children: list<array<string, mixed>>}
     */
    private function branch(ContentSlotLink $link): array
    {
        return [
            'title' => $link->label,
            'href' => $link->url ?? '',
            'opensInNewWindow' => $link->opensInNewWindow,
            'children' => array_map($this->branch(...), $link->children),
        ];
    }
}
