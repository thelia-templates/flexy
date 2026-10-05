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
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\HttpKernel\Exception\RedirectException;
use Thelia\Domain\Order\Exception\StockShortageException;

/**
 * What the buyer is told when the stock runs out while the order is being placed.
 *
 * The pre-check of the placement reads the stock before the order exists, and the core decrements it atomically once it
 * does: a line sold out in between raises StockShortageException, named after the product. The answer is the cart, which
 * spells out the shortage line by line, with a sentence in the language of the page. The wording of the exception is
 * written for a log, so it is not what is shown.
 */
final readonly class CheckoutStockRefusal
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function answer(StockShortageException $shortage, string $cartPath, ?object $session): RedirectException
    {
        $sentence = null === $shortage->productReference || '' === $shortage->productReference
            ? $this->translator->trans('Some products in your cart are no longer available in the requested quantity. Please adjust the quantities before ordering.')
            : $this->translator->trans('The stock of %reference% ran out while your order was placed. Please adjust your cart.', ['%reference%' => $shortage->productReference]);

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $sentence);
        }

        // The core redirect listener drops the message of the exception: the flash is what the buyer reads.
        return new RedirectException($cartPath, 302, $sentence);
    }
}
