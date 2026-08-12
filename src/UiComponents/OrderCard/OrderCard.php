<?php

declare(strict_types=1);

namespace FlexyBundle\UiComponents\OrderCard;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Api\Service\DataAccess\DataAccessService;

#[AsTwigComponent(name: 'Flexy:OrderCard', template: '@UiComponents/OrderCard/OrderCard.html.twig')]
class OrderCard
{
    public int $orderId;

    public function __construct(private readonly DataAccessService $dataAccessService)
    {
    }

    /**
     * The API returns nothing for an order the current customer cannot read, or for one
     * whose related rows no longer resolve. A single such order used to take the whole
     * order list down, so the card renders nothing instead.
     */
    public function getOrder(): ?array
    {
        return $this->dataAccessService->resources('/api/front/account/orders/'.$this->orderId);
    }
}
