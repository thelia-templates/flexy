<?php

declare(strict_types=1);

namespace FlexyBundle\Search;

/**
 * An engine that also looks for the equivalent terms of the search dictionary of the core
 * (synonyms). Without it, the engine receives the term alone and the synonyms do nothing.
 */
interface ProductSearchEngineWithAlternativeTermsInterface extends ProductSearchEngineInterface
{
    /**
     * Ids of the products matching the term or any of its alternative terms in the locale,
     * most relevant first, under the same rules as productIds().
     *
     * @param list<string> $alternativeTerms
     *
     * @return list<int>
     */
    public function productIdsMatchingAnyTerm(string $term, array $alternativeTerms, string $locale, int $limit): array;
}
