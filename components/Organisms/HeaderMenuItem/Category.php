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

use FlexyBundle\Service\NavigationTree;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Api\Service\DataAccess\DataAccessService;

#[AsTwigComponent]
class Category extends AbstractHeaderMenuItem
{
    public int|string|null $id = null;
    public string $menuKey = '';
    public string $title = '';
    public string $href = '';
    public array $columns = [];
    public array $leafLinks = [];
    public bool $showSeeMore = false;

    public function __construct(
        DataAccessService $dataAccessService,
        private readonly NavigationTree $navigationTree,
    ) {
        parent::__construct($dataAccessService);
    }

    public function mount(int|string|null $id = null, ?string $title = null, ?string $href = null): void
    {
        $this->id = $id;
        $this->menuKey = $this->buildMenuKey($id);

        $titleAndHref = $this->resolveTitleAndHref('/api/front/categories', $id, $title, $href);

        if (null === $titleAndHref) {
            $this->served = false;

            return;
        }

        [$this->title, $this->href] = $titleAndHref;

        if ($id === null) {
            return;
        }

        $result = $this->buildMegaMenu($this->navigationTree->categoryBranches($id));

        $this->columns = $result['columns'];
        $this->leafLinks = $result['leafLinks'];
    }
}
