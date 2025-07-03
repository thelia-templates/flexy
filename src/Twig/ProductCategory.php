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

namespace FlexyBundle\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use TwigEngine\Service\DataAccess\DataAccessService;

#[AsTwigComponent(template: 'components/Layout/ProductCategory/ProductCategory.html.twig')]
class ProductCategory
{
    public string $categoryId;

    public function __construct(private DataAccessService $dataAccessService, private TranslatorInterface $translator)
    {
    }

    public function getCategories(): array
    {
        $categories = $this->dataAccessService->resources('/api/front/categories', [
            'itemsPerPage' => 3,
        ]);

        return array_map(function ($item) {
            return [
                'title' => $item['i18ns']['title'] ?? '',
                'button' => [
                    'label' => $this->translator->trans('Discover'),
                    'href' => $item['publicUrl'],
                ],
                'img' => [
                    'url' => '/legacy-image-library/category_image_'.$item['id'].'/full/%5E*!386,280/0/default.webp',
                    'alt' => $item['i18ns']['title'] ?? '',
                ],
                'url' => $item['publicUrl'],
            ];
        }, $categories);
    }
}
