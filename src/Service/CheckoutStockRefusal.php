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

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\HttpKernel\Exception\RedirectException;
use Thelia\Domain\Catalog\Stock\Exception\StockReservationConflictException;
use Thelia\Domain\Catalog\Stock\Exception\StockReservationRefusedException;
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
    public function __construct(
        #[Autowire(service: 'translator')]
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * The sentence for the buyer, in the language of the page: what the refusal of a hold says (a line another cart holds,
     * a tie the database broke) is not what a placement that ran out of stock says.
     */
    public function sentenceOf(StockShortageException $shortage): string
    {
        if ($shortage instanceof StockReservationConflictException) {
            return $this->translator->trans('Another buyer took the same products at the same moment. Please try again.');
        }

        $hasReference = null !== $shortage->productReference && '' !== $shortage->productReference;

        if ($shortage instanceof StockReservationRefusedException) {
            return $hasReference
                ? $this->translator->trans('%reference% is held in another cart for the moment. Please adjust your cart or try again later.', ['%reference%' => $shortage->productReference])
                : $this->translator->trans('Some products in your cart are held in another cart for the moment. Please adjust your cart or try again later.');
        }

        return $hasReference
            ? $this->translator->trans('The stock of %reference% ran out while your order was placed. Please adjust your cart.', ['%reference%' => $shortage->productReference])
            : $this->translator->trans('Some products in your cart are no longer available in the requested quantity. Please adjust the quantities before ordering.');
    }

    public function answer(StockShortageException $shortage, string $cartPath, ?object $session): RedirectException
    {
        $sentence = $this->sentenceOf($shortage);

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $sentence);
        }

        // The core redirect listener drops the message of the exception: the flash is what the buyer reads.
        return new RedirectException($cartPath, 302, $sentence);
    }
}
