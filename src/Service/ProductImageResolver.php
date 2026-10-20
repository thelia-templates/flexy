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

use Symfony\Contracts\Service\ResetInterface;
use TheliaLibrary\Service\ImageService;

/**
 * The product payload carries no image, so every card would fetch its own. Listings
 * call preload() once with the whole page, turning N calls into one.
 *
 * The images are read through the library's own service, translations included, and
 * kept there: the getImages() each card then makes with the id found here is answered
 * from that read instead of costing a query per card.
 *
 * Deliberately not readonly: the resolved map is the point of this service. Under
 * php-fpm Symfony builds it per request, so the map never outlives one page render;
 * ResetInterface makes that hold under a worker runtime too.
 */
final class ProductImageResolver implements ResetInterface
{
    private const SOURCE_TYPE = 'product';

    /** @var array<int, int|null> */
    private array $imageIdByProduct = [];

    public function __construct(
        private readonly ImageService $imageService,
    ) {
    }

    /**
     * @param array<int, int> $productIds
     */
    public function preload(array $productIds): void
    {
        $missing = array_values(array_diff(
            array_unique(array_filter($productIds)),
            array_keys($this->imageIdByProduct),
        ));

        if ([] === $missing) {
            return;
        }

        $this->imageService->preloadSourceImages(self::SOURCE_TYPE, $missing);

        foreach ($missing as $productId) {
            // Visible images only, by position: the one getImages() renders for a card.
            $images = $this->imageService->getImageDataWithType([
                'source_type' => self::SOURCE_TYPE,
                'source_id' => $productId,
                'limit' => 1,
            ]);

            $this->imageIdByProduct[$productId] = isset($images[0]['id']) ? (int) $images[0]['id'] : null;
        }
    }

    public function firstImageId(int $productId): ?int
    {
        if (!\array_key_exists($productId, $this->imageIdByProduct)) {
            $this->preload([$productId]);
        }

        return $this->imageIdByProduct[$productId];
    }

    public function reset(): void
    {
        $this->imageIdByProduct = [];
    }
}
