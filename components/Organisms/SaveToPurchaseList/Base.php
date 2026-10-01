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

namespace FlexyBundle\Components\Organisms\SaveToPurchaseList;

use FlexyBundle\Service\PurchaseListChoices;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Domain\Customer\CustomerFacade;
use Thelia\Domain\CustomerList\PurchaseListFacade;

/**
 * "Save as a purchase list" for lines the customer already has, a cart or a past order:
 * a button that opens the choice between a new list and one the customer may change,
 * posted to the given action. Renders nothing for a visitor who is not signed in.
 */
#[AsTwigComponent]
class Base
{
    public string $action = '';

    public string $defaultTitle = '';

    public string $id = 'save-to-purchase-list';

    public bool $signedIn = false;

    /** @var list<array{value: string, label: string}> */
    public array $lists = [];

    public int $maxTitleLength = PurchaseListFacade::MAX_TITLE_LENGTH;

    public function __construct(
        private readonly CustomerFacade $customerFacade,
        private readonly PurchaseListChoices $choices,
    ) {
    }

    public function mount(string $action): void
    {
        $this->action = $action;
        $customer = $this->customerFacade->getCurrentCustomer();

        if (null === $customer) {
            return;
        }

        $this->signedIn = true;
        $this->lists = $this->choices->writableListsOf($customer);
    }
}
