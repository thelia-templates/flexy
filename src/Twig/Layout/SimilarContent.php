<?php

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlexyBundle\Twig\Layout;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use TwigEngine\Service\DataAccess\DataAccessService;

#[AsTwigComponent(template: '@components/Layout/SimilarContent/SimilarContent.html.twig')]
class SimilarContent
{
    public array $similarContents;

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
        $contents = $this->dataAccessService->resources('/api/front/contents', [
            'itemsPerPage' => 3,
        ]);

        return array_map(function ($item) {
            return [
                'title' => $item['i18ns']['title'] ?? '',
                'date' => $item['createdAt'],
                'url' => $item['publicUrl'],
            ];
        }, $contents);
    }
}
