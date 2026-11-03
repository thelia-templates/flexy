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

namespace FlexyBundle\Service;

use Thelia\Api\Service\DataAccess\AttributeAccessService;

/**
 * The promotions the buyer typed a code for and the core left out of the cart, each with the promotion
 * kept instead.
 *
 * A core that predates the rule answers an attribute it does not know with an empty string, so the shape
 * is checked rather than assumed: an entry whose code or titles are not plain, non-empty text is dropped.
 */
final readonly class ExcludedPromotionsReader
{
    public function __construct(private AttributeAccessService $attributeAccessService)
    {
    }

    /**
     * @return list<array{code: string, title: string, winner_title: string}>
     */
    public function read(): array
    {
        $excluded = $this->attributeAccessService->attributeCart('excluded_promotions');

        if (!\is_array($excluded)) {
            return [];
        }

        $promotions = [];

        foreach ($excluded as $promotion) {
            if (!\is_array($promotion)) {
                continue;
            }

            $code = $this->text($promotion['code'] ?? null);
            $title = $this->text($promotion['title'] ?? null);
            $winnerTitle = $this->text($promotion['winner_title'] ?? null);

            if (null === $code || null === $title || null === $winnerTitle) {
                continue;
            }

            $promotions[] = ['code' => $code, 'title' => $title, 'winner_title' => $winnerTitle];
        }

        return $promotions;
    }

    private function text(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
