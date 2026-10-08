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

use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Thelia\Core\HttpKernel\Exception\RedirectException;
use Thelia\Domain\Checkout\Exception\CheckoutException;

/**
 * What the buyer is told when a step of the checkout refuses the order at placement.
 *
 * The core asks every declared step before placing the order, the steps of modules included, and each refusal is a
 * CheckoutException worded for the buyer. Whatever the step, the answer is the same: the step that still has something
 * missing, with the reason on it.
 */
final readonly class CheckoutStepRefusal
{
    public function answer(CheckoutException $refusal, string $stepPath, ?object $session): RedirectException
    {
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $refusal->getMessage());
        }

        // The core redirect listener drops the message of the exception: the flash is what the buyer reads.
        return new RedirectException($stepPath, 302, $refusal->getMessage());
    }
}
