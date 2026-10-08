<?php

declare(strict_types=1);

namespace FlexyBundle\Search;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('flexy.product_search_engine')]
interface ProductSearchEngineInterface
{
    /**
     * Ids of the products matching the term in the locale, most relevant first.
     * Visibility, pagination and sorting stay with the theme: return every match up to $limit,
     * including products that may turn out invisible.
     *
     * @return list<int>
     */
    public function productIds(string $term, string $locale, int $limit): array;
}
