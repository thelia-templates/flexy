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

namespace FlexyBundle\DTO;

/**
 * What a buyer may answer a quote with, on the page of the quote: nothing, once the quote no longer waits
 * for them, and otherwise a counter-proposal and a refusal, and the acceptance when their role may also
 * place orders. A quote already accepted whose payment did not go through is offered the payment again.
 */
final readonly class QuoteReplyView
{
    public function __construct(
        public bool $canCounter = false,
        public bool $canReject = false,
        public bool $canAccept = false,
        public bool $canResumePayment = false,
    ) {
    }

    public function offersAnything(): bool
    {
        return $this->canCounter || $this->canReject || $this->canAccept || $this->canResumePayment;
    }
}
