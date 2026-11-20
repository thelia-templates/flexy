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

use Propel\Runtime\Exception\PropelException;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Checkout\DTO\CheckoutStepView;
use Thelia\Domain\Checkout\Service\CheckoutProgressionService;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\Cart;
use Thelia\Model\CheckoutStep;

/**
 * The steps the progression bar draws, for the cart in hand.
 *
 * Read from the checkout configuration rather than written out per page: a shop that
 * turns the delivery step off, reorders the tunnel or renames a step has one place to
 * do it, and a cart with nothing to ship is not shown a stop it will never make. The
 * wordings come translated from the core, so the bar says what the merchant wrote.
 */
final readonly class CheckoutTrail
{
    /** The step the company of a buyer checks the order against: it settles the placement and has nothing to draw. */
    public const COMPANY_SPENDING_STEP = 'company.spending_limit';

    public function __construct(
        private CheckoutProgressionService $progression,
        private CheckoutStepRouteResolver $routes,
        private CartFacade $cartFacade,
        private LangService $langService,
        private PlacedOrderMemory $placedOrderMemory,
    ) {
    }

    /**
     * @param bool $identifiesAfterTheCart whether leaving the cart takes this visitor to
     *                                     the identification page rather than to the
     *                                     next step of the tunnel
     *
     * @return list<array{code: string, text: string, link: string|null, translate: bool}>
     *
     * @throws PropelException
     */
    public function of(Cart $cart, bool $identifiesAfterTheCart = false): array
    {
        return $this->drawn(
            $this->progression->activeSteps($cart, $this->langService->getLocale()),
            $identifiesAfterTheCart,
        );
    }

    /**
     * The bar of the session's cart, or of the tunnel as it is configured when there is
     * no cart to read one from.
     *
     * A buyer coming back from a payment gateway may come back without the session they
     * left with, and the confirmation and failure pages still have a bar to draw. The
     * cart stood in for here is never saved, so reading it opens nothing.
     *
     * @return list<array{code: string, text: string, link: string|null, translate: bool}>
     *
     * @throws PropelException
     */
    public function ofTheSessionCart(bool $identifiesAfterTheCart = false): array
    {
        return $this->of($this->cartFacade->getCartFromSession() ?? new Cart(), $identifiesAfterTheCart);
    }

    /**
     * The bar of the order this session has just placed — what the confirmation and the
     * failed-payment pages draw.
     *
     * Neither of them may read the cart: the placement empties it, and the empty one that
     * takes its place answers a different tunnel. An order with nothing to ship is where
     * that shows — its delivery step was left out, an empty cart is not virtual, and the
     * bar would put back a stop the buyer never made. So the steps are the ones taken at
     * the placement, and the wordings are read again from the configuration in the
     * language of the page drawing them.
     *
     * A session with nothing remembered — one that placed its order before this memory
     * existed, one that came back from a gateway without its cookie — falls back to the
     * cart, which is what these pages read before.
     *
     * @return list<array{code: string, text: string, link: string|null, translate: bool}>
     *
     * @throws PropelException
     */
    public function ofTheOrderJustPlaced(): array
    {
        $walked = $this->placedOrderMemory->stepsWalked();

        if (null === $walked) {
            return $this->ofTheSessionCart();
        }

        // Read off a cart there is nothing in rather than off the session's: nothing is
        // left out for such a cart, so the tunnel comes back whole and the steps the
        // order actually made are picked out of it. The cart stood in for is never saved.
        $configured = $this->progression->activeSteps(new Cart(), $this->langService->getLocale());

        return $this->drawn(
            array_values(array_filter(
                $configured,
                static fn (CheckoutStepView $step): bool => \in_array($step->code, $walked, true),
            )),
            false,
        );
    }

    /**
     * @param list<CheckoutStepView> $steps
     *
     * @return list<array{code: string, text: string, link: string|null, translate: bool}>
     *
     * @throws PropelException
     */
    private function drawn(array $steps, bool $identifiesAfterTheCart): array
    {
        $entries = [];
        $previousWasTheCart = false;

        foreach ($steps as $step) {
            // A check with no screen: it refuses an order, it is not a stop of the buyer's way.
            if (self::COMPANY_SPENDING_STEP === $step->code) {
                continue;
            }

            $standsForTheIdentification = $identifiesAfterTheCart && $previousWasTheCart;
            $previousWasTheCart = CheckoutStep::CODE_CART === $step->code;

            $entries[] = [
                'code' => $step->code,
                // The identification is not a step of the tunnel — it is how a visitor
                // with no account gets into it — so it borrows the name of the step it
                // stands in front of. Announcing the next step and then landing on the
                // identification page made the bar rename itself between two pages.
                'text' => $standsForTheIdentification ? 'Identification' : $step->title,
                // No link on the entry that stands for the identification: it would be
                // the url of the step behind it, and that step turns a visitor with no
                // session straight back to the identification page. A bar entry that
                // lands on the page it was clicked from reads as a broken link, which is
                // why the page this replaced wrote no href there either.
                'link' => $standsForTheIdentification ? null : $this->linkFor($step->code),
                // A step of the configuration carries the title the merchant wrote, in
                // the language the buyer is reading: putting it through the theme
                // catalogue would be looking up a sentence that is already the answer.
                'translate' => $standsForTheIdentification,
            ];
        }

        return $entries;
    }

    /**
     * @throws PropelException
     */
    private function linkFor(string $code): ?string
    {
        // One page: every step is on the screen the buyer is already looking at, so the
        // bar names them and leads nowhere. The confirmation is never a link in either
        // form — it is read once the order exists, and not a moment before.
        if ($this->routes->isOnePage() || CheckoutStep::CODE_CONFIRMATION === $code) {
            return null;
        }

        return $this->routes->pathFor($code);
    }
}
