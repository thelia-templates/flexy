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

use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Catalog\DTO\ReferenceQuantityLines;
use Thelia\Domain\QuickOrder\DTO\QuickOrderTable;
use Thelia\Domain\QuickOrder\Exception\QuickOrderRateLimitedException;
use Thelia\Domain\QuickOrder\QuickOrderFacade;
use Thelia\Model\Currency;
use Thelia\Model\Customer;

/**
 * Ordering by reference for the signed-in customer of the front office.
 *
 * The core decides everything: what a reference means, its price, whether it may go to
 * the cart, and how often an account may ask. This service only fills in what the front
 * API cannot know for a session: the customer is the one signed in, the table is priced
 * in the currency the visitor browses in, and the lines go to the cart of the session.
 */
final readonly class QuickOrderService
{
    public function __construct(
        private QuickOrderFacade $quickOrderFacade,
        private CartFacade $cartFacade,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @throws QuickOrderRateLimitedException
     */
    public function resolve(Customer $customer, ReferenceQuantityLines $lines): QuickOrderTable
    {
        return $this->quickOrderFacade->resolve($customer, $lines, $this->currency());
    }

    /**
     * @throws QuickOrderRateLimitedException
     * @throws \Thelia\Domain\CustomerList\Exception\PurchaseListNotFoundException
     */
    public function resolvePurchaseList(Customer $customer, int $listId): QuickOrderTable
    {
        return $this->quickOrderFacade->resolvePurchaseList($customer, $listId, $this->currency());
    }

    /**
     * @throws QuickOrderRateLimitedException
     */
    public function addToCart(Customer $customer, ReferenceQuantityLines $lines): QuickOrderTable
    {
        return $this->quickOrderFacade->addToCart($customer, $this->cartFacade->getOrCreateForCustomer($customer), $lines);
    }

    private function currency(): Currency
    {
        $session = $this->requestStack->getCurrentRequest()?->hasSession() ? $this->requestStack->getSession() : null;

        return $session instanceof Session ? $session->getCurrency() : Currency::getDefaultCurrency();
    }
}
