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

#[AsTwigComponent]
class Base extends AbstractHeaderMenuItem
{
    public int|string|null $id = null;
    public string $menuKey = '';
    public string $title = '';
    public string $href = '';
    public array $columns = [];
    public array $children = [];
    public array $leafLinks = [];
    public bool $showSeeMore = false;

    public function mount(int|string|null $id = null, string $title = '', string $href = '', array $children = []): void
    {
        if ($id === null) {
            return;
        }

        $this->id = $id;
        $this->menuKey = $this->buildMenuKey($id);
        $this->title = $title;
        $this->href = $href;

        $result = $this->buildMegaMenu($children);
        $this->columns = $result['columns'];
        $this->leafLinks = $result['leafLinks'];
    }
}
