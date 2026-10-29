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

namespace FlexyBundle\Components\Forms\OrderReturn;

use FlexyBundle\Service\OrderLineType;
use FlexyBundle\Service\OrderProductResolver;
use FlexyBundle\Service\OrderReturnRequestService;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Model\OrderReturn as OrderReturnModel;

/**
 * The return request form: one row per order line that may still be returned, a reason,
 * a comment and what the customer expects the return to settle into.
 *
 * Plain form, posted once — no LiveComponent. There is no field whose answer changes
 * another, and the quantities are re-read server-side on submit anyway, so a live round
 * trip would buy nothing and cost the pitfalls that come with it.
 */
#[AsTwigComponent]
class Request
{
    public int $orderId = 0;

    /**
     * The quantity still returnable per order product id, as the server computed it.
     * It bounds the number input, and the same computation refuses a larger one on submit.
     *
     * @var array<int, float>
     */
    public array $returnableQuantities = [];

    /** @var array<int, array{orderProduct: array<string, mixed>, product: \FlexyBundle\DTO\ProductDTO|null, pse: array<string, mixed>|null, imageId: int|null}> */
    public array $lines = [];

    /** @var array<int, array<string, mixed>> */
    public array $reasons = [];

    /** @var list<string> */
    public array $resolutions = OrderReturnModel::RESOLUTIONS;

    public int $maxCommentLength = OrderReturnRequestService::MAX_COMMENT_LENGTH;

    public function __construct(
        private readonly DataAccessService $dataAccessService,
        private readonly OrderProductResolver $orderProductResolver,
    ) {
    }

    /**
     * @param array<int, float> $returnableQuantities
     */
    public function mount(int $orderId, array $returnableQuantities): void
    {
        $this->orderId = $orderId;
        $this->returnableQuantities = $returnableQuantities;

        $order = $this->dataAccessService->resources('/api/front/account/orders/'.$orderId);
        $orderProducts = \is_array($order) ? ($order['orderProducts'] ?? []) : [];

        // Only the lines the server says are still returnable ever reach the form. A line
        // left out here is a line the browser cannot name back, and one it names anyway is
        // refused on submit by the same eligibility service that filled this list. A component
        // of a bundle is never offered on its own, whatever list it was handed: the bundle is
        // what a customer returns, and the core restocks the components with it.
        $returnable = array_filter(
            \is_array($orderProducts) ? $orderProducts : [],
            fn (array $orderProduct): bool => isset($this->returnableQuantities[(int) ($orderProduct['id'] ?? 0)])
                && !OrderLineType::isBundleComponent($orderProduct),
        );

        $this->lines = $this->orderProductResolver->resolveLines(array_values($returnable));

        $reasons = $this->dataAccessService->resources('/api/front/account/order_return_reasons', [
            'visible' => true,
            'order[position]' => 'asc',
        ]);

        $this->reasons = \is_array($reasons) ? $reasons : [];
    }

    public function returnableQuantity(int $orderProductId): float
    {
        return $this->returnableQuantities[$orderProductId] ?? 0.0;
    }
}
