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

use Thelia\Domain\CustomerList\PurchaseListFacade;
use Thelia\Model\Customer;

/**
 * Where lines can be saved: a new list, or one of the lists the customer may change.
 * The choice of a new list is not empty: an empty choice is a placeholder in the theme.
 */
final readonly class PurchaseListChoices
{
    public const string NEW_LIST = 'new';

    public function __construct(
        private PurchaseListFacade $purchaseListFacade,
    ) {
    }

    /**
     * @return list<array{value: string, label: string}> the lists the customer may change, in the order of the account page
     */
    public function writableListsOf(Customer $customer): array
    {
        $choices = [];

        foreach ($this->purchaseListFacade->listVisibleFor($customer) as $list) {
            if ($this->purchaseListFacade->canWrite($customer, $list)) {
                $choices[] = ['value' => (string) $list->getId(), 'label' => (string) $list->getTitle()];
            }
        }

        return $choices;
    }
}
