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

namespace FlexyBundle\UiComponents\SimilarContent;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Api\Service\DataAccess\DataAccessService;

#[AsTwigComponent(name: 'Flexy:SimilarContent', template: '@UiComponents/SimilarContent/SimilarContent.html.twig')]
class SimilarContent
{
    public array $similarContents;
    public int $itemsPerPage = 3;
    public ?int $folderId = null;
    public ?array $excludeId = null;

    public function __construct(private DataAccessService $dataAccessService)
    {
    }

    public function mount(array $similarContents = []): void
    {
        if (\count($similarContents) > 0) {
            $this->similarContents = $similarContents;
        }
    }

    public function similarContents(): array
    {
        $params = [
            'itemsPerPage' => $this->itemsPerPage,
            'visible' => true,
        ];

        if (null !== $this->folderId) {
            $params['contentFolders.folder.id'] = $this->folderId;
        }

        if (null !== $this->excludeId && \count($this->excludeId) > 0) {
            $params['not_in[id]'] = $this->excludeId;
        }

        $contents = $this->dataAccessService->resources('/api/front/contents', $params);

        return array_map(fn($item) => [
            'id' => $item['id'],
            'title' => $item['i18ns']['title'] ?? '',
            'date' => $item['createdAt'],
            'url' => $item['publicUrl'],
        ], $contents);
    }
}
