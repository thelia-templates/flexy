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

namespace FlexyBundle\Components\Layouts\AccountPurchaseLists;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\CustomerList\PurchaseListFacade;
use Thelia\Model\Customer;

/**
 * The purchase lists of the signed-in customer, most recently changed first, each with
 * its number of lines and whether the customer may change it.
 */
#[AsTwigComponent]
class Base
{
    /** @var list<array{id: int, title: string, itemCount: int, updatedAt: \DateTimeInterface|null, writable: bool}> */
    public array $lists = [];

    public int $maxLists = PurchaseListFacade::MAX_LISTS_PER_CUSTOMER;

    public int $maxTitleLength = PurchaseListFacade::MAX_TITLE_LENGTH;

    public function __construct(
        private readonly PurchaseListFacade $purchaseListFacade,
        private readonly SecurityContext $securityContext,
    ) {
    }

    public function mount(): void
    {
        $customer = $this->securityContext->getCustomerUser();

        if (!$customer instanceof Customer) {
            return;
        }

        $lists = $this->purchaseListFacade->listVisibleFor($customer);
        $counts = $this->purchaseListFacade->countItemsOf($lists);

        foreach ($lists as $list) {
            $this->lists[] = [
                'id' => (int) $list->getId(),
                'title' => (string) $list->getTitle(),
                'itemCount' => $counts[(int) $list->getId()] ?? 0,
                'updatedAt' => $list->getUpdatedAt(),
                'writable' => $this->purchaseListFacade->canWrite($customer, $list),
            ];
        }
    }
}
