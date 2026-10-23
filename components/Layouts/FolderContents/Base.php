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

namespace FlexyBundle\Components\Layouts\FolderContents;

use FlexyBundle\Service\CollectionPaginator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
class Base
{
    private const ITEMS_PER_PAGE = 12;

    /** @var list<array<string, mixed>> */
    public array $contents = [];

    /** @var array<string, int> */
    public array $pagination = [];

    public function __construct(
        private readonly CollectionPaginator $paginator,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Without a page given, the one of the query string, read raw: CollectionPaginator
     * makes sense of whatever it holds.
     */
    public function mount(int $folderId, mixed $page = null): void
    {
        $contents = $this->paginator->page('/api/front/contents', [
            'contentFolders.folder.id' => $folderId,
            'visible' => true,
        ], $page ?? CollectionPaginator::requestedPage($this->requestStack->getCurrentRequest()), self::ITEMS_PER_PAGE);

        $this->contents = $contents->members;
        $this->pagination = $contents->pagination();
    }
}
