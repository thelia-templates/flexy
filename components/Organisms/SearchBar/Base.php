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

namespace FlexyBundle\Components\Organisms\SearchBar;

use FlexyBundle\DTO\ProductDTO;
use FlexyBundle\Service\ProductImageResolver;
use FlexyBundle\Service\ProductSearch;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use Thelia\Api\Service\DataAccess\DataAccessService;

#[AsLiveComponent]
class Base
{
    use DefaultActionTrait;

    public const SUGGESTION_LIMIT = 6;

    /**
     * Survives closing and reopening the panel on purpose: the element is never removed from the
     * DOM, so the term and its suggestions are still there when it comes back.
     */
    #[LiveProp(writable: true)]
    public string $query = '';

    /** @var array{products: list<ProductDTO>, total: int}|null */
    private ?array $result = null;

    public function __construct(
        private readonly ProductSearch $productSearch,
        private readonly DataAccessService $dataAccessService,
        private readonly ProductImageResolver $productImageResolver,
    ) {
    }

    #[ExposeInTemplate]
    public function getTotal(): int
    {
        return $this->result()['total'];
    }

    /**
     * @return list<ProductDTO>
     */
    #[ExposeInTemplate]
    public function getProducts(): array
    {
        return $this->result()['products'];
    }

    /**
     * Memoized: the template reads both the tiles and the total, one search covers both.
     *
     * @return array{products: list<ProductDTO>, total: int}
     */
    private function result(): array
    {
        if ($this->result !== null) {
            return $this->result;
        }

        $this->result = $this->productSearch->search($this->query, itemsPerPage: self::SUGGESTION_LIMIT);

        // One lookup for the whole panel rather than one per tile
        $productIds = array_map(static fn (ProductDTO $product): int => $product->id, $this->result['products']);
        $this->productImageResolver->preload($productIds);

        return $this->result;
    }

    /**
     * TODO Unfiltered: the core declares no `title` filter on the category resource, so this lists
     * the first categories whatever is typed. Fixed upstream by adding `'title' => 'word_start'`
     * to Category's SearchFilter — the parameter is already sent here.
     *
     * @return array<int, array<string, mixed>>
     */
    #[ExposeInTemplate]
    public function getCategories(): array
    {
        if (trim($this->query) === '') {
            return [];
        }

        return $this->dataAccessService->resources('/api/front/categories', [
            'title' => trim($this->query),
            'visible' => true,
            'itemsPerPage' => self::SUGGESTION_LIMIT,
        ]) ?? [];
    }
}
