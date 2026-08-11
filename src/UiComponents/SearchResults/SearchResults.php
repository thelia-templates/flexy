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

namespace FlexyBundle\UiComponents\SearchResults;

use FlexyBundle\DTO\ProductDTO;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Api\Service\DataAccess\DataAccessService;

#[AsTwigComponent(name: 'Flexy:SearchResults', template: '@UiComponents/SearchResults/SearchResults.html.twig')]
class SearchResults
{
    public const ITEMS_PER_PAGE = 9;

    public string $query = '';
    public array $products = [];
    public int $totalItems = 0;
    public array $pagination = [];

    public function __construct(
        private readonly DataAccessService $dataAccessService,
    ) {}

    public function mount(string $query = '', int $page = 1): void
    {
        $this->query = trim($query);
        $page = max(1, $page);

        // An empty term would drop the title filter and list the whole catalogue
        if ($this->query === '') {
            return;
        }

        $response = $this->dataAccessService->resources('/api/front/products', [
            'title' => $this->query,
            'visible' => true,
            'itemsPerPage' => self::ITEMS_PER_PAGE,
            'page' => $page,
        ], 'jsonld');

        if (!\is_array($response)) {
            return;
        }

        $this->products = ProductDTO::fromCollection($response['hydra:member'] ?? []);
        $this->totalItems = (int) ($response['hydra:totalItems'] ?? 0);
        $this->pagination = [
            'totalItems' => $this->totalItems,
            'itemsPerPage' => self::ITEMS_PER_PAGE,
            'currentPage' => $page,
            'baseUrl' => '?'.http_build_query(['query' => $this->query]),
        ];
    }
}
