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

use FlexyBundle\DTO\QuoteReplyView;
use Psr\Clock\ClockInterface;
use Thelia\Domain\Company\CompanyPermission;
use Thelia\Domain\Company\Service\CompanyPermissionChecker;
use Thelia\Domain\Quote\Enum\QuoteState;
use Thelia\Domain\Quote\QuoteFacade;
use Thelia\Domain\Quote\Service\QuoteAccess;
use Thelia\Model\Customer;
use Thelia\Model\OrderQuery;
use Thelia\Model\Quote;
use Thelia\Model\QuoteQuery;

/**
 * Whether the buyer in hand is offered to answer a quote, and with what.
 *
 * Only a quote the shop has answered and whose validity is not over waits for the buyer: a quote the buyer
 * already countered waits for the shop, and an expired one waits for nobody. The state read is the effective
 * one, on the day of the clock of the core, so a quote whose last day went by is not offered, whatever its stored
 * state says. Answering is the right of whoever reads the quote and may ask for quotes; accepting also needs the
 * right to place orders, which a buyer of a company holds through their role.
 *
 * A quote already accepted whose order does not stand (the cart was lost, or the order was cancelled) is offered
 * the payment again, to whoever could have accepted it.
 *
 * This only decides what the page draws. The core judges every answer again: a request that was not offered
 * is refused all the same.
 */
final readonly class QuoteReply
{
    public function __construct(
        private QuoteAccess $access,
        private QuoteFacade $quotes,
        private CompanyPermissionChecker $permissionChecker,
        private ClockInterface $clock,
    ) {
    }

    public function offeredFor(?Customer $customer, int $quoteId): QuoteReplyView
    {
        $quote = QuoteQuery::create()->findPk($quoteId);

        if (null === $customer || $customer->isGuest() || null === $quote || QuoteState::Draft->value === $quote->getState()) {
            return new QuoteReplyView();
        }

        return $this->offeredOn($customer, $quote);
    }

    public function offeredOn(Customer $customer, Quote $quote): QuoteReplyView
    {
        $state = $this->quotes->effectiveState($quote, $this->clock->now()->setTime(0, 0));

        if (QuoteState::Accepted === $state) {
            return new QuoteReplyView(canResumePayment: $this->waitsForItsPayment($quote) && $this->mayPay($customer, $quote));
        }

        if (QuoteState::Quoted !== $state || !$this->access->canAnswer($customer, $quote)) {
            return new QuoteReplyView();
        }

        return new QuoteReplyView(
            canCounter: true,
            canReject: true,
            canAccept: $this->permissionChecker->can($customer, CompanyPermission::ORDER_PLACE),
        );
    }

    /**
     * A quote that stands accepted and has no order that stands: the cart was lost on the way to the payment, or the
     * order was cancelled and gave the quote back. The payment is taken again from the same acceptance, which gives the
     * cart of the quote back.
     */
    private function waitsForItsPayment(Quote $quote): bool
    {
        foreach (OrderQuery::create()->filterByQuoteId($quote->getId())->find() as $order) {
            if (!($order->getOrderStatus()?->isCancelled() ?? false)) {
                return false;
            }
        }

        return true;
    }

    private function mayPay(Customer $customer, Quote $quote): bool
    {
        return $this->access->canAnswer($customer, $quote) && $this->permissionChecker->can($customer, CompanyPermission::ORDER_PLACE);
    }
}
