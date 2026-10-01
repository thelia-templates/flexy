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

namespace FlexyBundle\Components\Layouts\Header;

use FlexyBundle\Service\NavigationTree;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Core\Content\Slot\ContentSlotLink;
use Thelia\Core\Content\Slot\ContentSlots;
use Thelia\Core\Content\Slot\ContentSlotService;
use Thelia\Domain\Localization\Service\LangService;

/**
 * The main navigation: the top-level categories, then the links of the `header_links`
 * content slot. The theme knows no content or folder id: the shop, or a module such as a
 * CMS, decides what the slot holds.
 */
#[AsTwigComponent]
class Base
{
    public array $menuItems = [];

    public function __construct(
        private readonly NavigationTree $navigationTree,
        private readonly ContentSlotService $contentSlotService,
        private readonly LangService $langService,
    ) {
    }

    public function mount(): void
    {
        $this->menuItems = array_map(
            static fn (array $category): array => [
                'type' => 'category',
                'id' => $category['id'],
                'title' => $category['title'],
                'href' => $category['href'],
            ],
            $this->navigationTree->categoryRoots(),
        );

        $links = $this->contentSlotService->links(ContentSlots::HEADER, (string) $this->langService->getLocale());

        foreach ($links as $link) {
            $this->menuItems[] = $this->menuItem($link);
        }
    }

    /**
     * A folder the slot names without children is drawn the way it always was, its
     * mega-menu built from the folder itself. A link that brings its own children is
     * drawn from them, whatever it points at. Anything else is a plain link.
     *
     * @return array<string, mixed>
     */
    private function menuItem(ContentSlotLink $link): array
    {
        if ([] !== $link->children) {
            return ['type' => 'tree', 'link' => $link];
        }

        if ('folder' === $link->source) {
            return [
                'type' => 'folder',
                'id' => $link->sourceId,
                'title' => $link->label,
                'href' => $link->url ?? '',
                'includeContents' => true,
            ];
        }

        return [
            'type' => 'link',
            'id' => $link->sourceId,
            'title' => $link->label,
            'href' => $link->url ?? '',
            'opensInNewWindow' => $link->opensInNewWindow,
        ];
    }
}
