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

namespace FlexyBundle\Components\Organisms\OrderNotes;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Domain\Customer\CustomerFacade;
use Thelia\Model\OrderQuery;

/**
 * The notes the shop wrote for its customer on one order, as read from the customer's
 * order page.
 *
 * What is shown is decided by the core, not here: the front read of an order publishes
 * `OrderCustomerNotesAddon.customerNotes`, which holds the history entries that are
 * notes *and* were ticked visible to the customer. Everything else of the journal —
 * status changes, mails sent, the administrator who signed in — never reaches this far.
 *
 * Renders nothing at all when the shop wrote no visible note, so an order nobody
 * commented on looks exactly as it did before.
 */
#[AsTwigComponent]
class Block
{
    public int $orderId = 0;

    /** @var array<int, array<string, mixed>> */
    public array $notes = [];

    public function __construct(
        private readonly DataAccessService $dataAccessService,
        private readonly CustomerFacade $customerFacade,
    ) {
    }

    public function mount(int $orderId): void
    {
        $this->orderId = $orderId;

        $customer = $this->customerFacade->getCurrentCustomer();

        if (null === $customer) {
            return;
        }

        // Scoped to the session customer rather than trusting the caller: the component
        // must stand on its own wherever it is dropped, and the order id travels in the url.
        $order = OrderQuery::create()
            ->filterByCustomerId($customer->getId())
            ->findPk($orderId);

        if (null === $order) {
            return;
        }

        // Same path and parameters as the page around it, so the memoized read is shared
        // rather than paid a second time.
        $payload = $this->dataAccessService->resources('/api/front/account/orders/'.$orderId);

        if (!\is_array($payload)) {
            return;
        }

        $notes = $payload['OrderCustomerNotesAddon']['customerNotes'] ?? [];

        $this->notes = \is_array($notes) ? array_values($notes) : [];
    }

    /**
     * Whether the shop addressed anything to the customer on this order.
     */
    public function isVisible(): bool
    {
        return [] !== $this->notes;
    }
}
