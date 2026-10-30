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

namespace FlexyBundle\Components\Organisms\OrderCard;

use FlexyBundle\Service\OrderLineType;
use FlexyBundle\Service\OrderProductResolver;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;

#[AsTwigComponent]
class Base
{
    /** Deliberate cap: never show more than 6 visuals, however wide the card gets. */
    public const RESOLVED_THUMBNAILS = 6;

    public int $orderId;

    /** @var array<string, mixed> */
    private array $order = [];

    /** @var array<int, array{url: string|null, imageId: int|null, title: string}>|null */
    private ?array $thumbnails = null;

    public function __construct(
        private readonly DataAccessService $dataAccessService,
        private readonly OrderProductResolver $orderProductResolver,
    ) {
    }

    public function mount(int $orderId): void
    {
        $this->orderId = $orderId;
        $this->order = $this->dataAccessService->resources('/api/front/account/orders/'.$orderId) ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(): array
    {
        return $this->order;
    }

    /**
     * The currency the order was placed in: the API payload does not publish its code.
     */
    public function getCurrencyCode(): string
    {
        if ([] === $this->order) {
            return 'EUR';
        }

        return OrderQuery::create()->findPk($this->orderId)?->getCurrency()?->getCode() ?: 'EUR';
    }

    public function getItemCount(): int
    {
        return \count($this->visibleLines());
    }

    /**
     * Only the first few lines get their visual resolved: past that the CSS collapses
     * them into the "+N" badge, so paying for their product and image would be waste.
     *
     * @return array<int, array{url: string|null, imageId: int|null, title: string}>
     */
    public function getThumbnails(): array
    {
        return $this->thumbnails ??= $this->orderProductResolver->resolveThumbnails(
            $this->visibleLines(),
            self::RESOLVED_THUMBNAILS,
        );
    }

    /**
     * The lines the card counts and shows: the components of a bundle are left out, the bundle
     * line stands for them (a card that counted both would say twice the articles bought, and
     * show the same product twice among the thumbnails).
     *
     * @return list<array<string, mixed>>
     */
    private function visibleLines(): array
    {
        return array_values(array_filter(
            $this->order['orderProducts'] ?? [],
            static fn (array $orderProduct): bool => !OrderLineType::isBundleComponent($orderProduct),
        ));
    }

    /**
     * The carrier page following the parcel, offered on a shipped order only (a custom
     * status equivalent to "sent" included): a tracking number may be typed before the
     * parcel has left.
     */
    public function getTrackingUrl(): ?string
    {
        $status = $this->order['orderStatus'] ?? [];
        $shipped = OrderStatus::CODE_SENT === ($status['code'] ?? null)
            || OrderStatus::CODE_SENT === ($status['equivalentCode'] ?? null);
        $url = $this->order['deliveryTrackingUrl'] ?? null;

        return $shipped && \is_string($url) && '' !== $url ? $url : null;
    }
}
