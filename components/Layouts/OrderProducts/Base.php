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

namespace FlexyBundle\Components\Layouts\OrderProducts;

use FlexyBundle\Service\OrderProductResolver;
use FlexyBundle\Service\PriceDisplay;
use FlexyBundle\Service\TaxDisplay;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Owns the lines of one order so they can be resolved together: a card left to itself
 * costs four API calls, which the page would pay once per line.
 */
#[AsTwigComponent]
class Base
{
    /** @var array<int, array{orderProduct: array<string, mixed>, product: \FlexyBundle\DTO\ProductDTO|null, pse: array<string, mixed>|null, imageId: int|null}> */
    public array $lines = [];

    public function __construct(
        private readonly OrderProductResolver $orderProductResolver,
        private readonly PriceDisplay $priceDisplay,
    ) {
    }

    /**
     * How the lines are read: the display the buyer of the order had when it was placed. A
     * page that does not hand the order over reads them taxed, as the orders placed before
     * the groups existed were.
     *
     * @param array<string, mixed>|null $order
     */
    public function displayFor(?array $order): TaxDisplay
    {
        return $this->priceDisplay->forOrder($order ?? []);
    }

    /**
     * @param array<int, array<string, mixed>> $orderProducts
     */
    public function mount(array $orderProducts = []): void
    {
        // The service lines of the order — a gift wrapping — are not things to pick off a
        // shelf: they carry no product to walk back to, no image and no page to link to,
        // and a card built for a good would render an empty one. They are stated with the
        // other charges, in the summary. A core whose payload predates the property says
        // nothing, and every line then reads as a good, which it is.
        $goods = array_filter(
            $orderProducts,
            static fn (array $orderProduct): bool => 'service' !== ($orderProduct['lineType'] ?? 'product'),
        );

        $this->lines = $this->orderProductResolver->resolveLines(array_values($goods));
    }
}
