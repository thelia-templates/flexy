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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Domain\Checkout\DTO\CheckoutStepView;
use Thelia\Domain\Checkout\Enum\CheckoutDisplayMode;
use Thelia\Domain\Checkout\Service\CheckoutProgressionService;
use Thelia\Model\Cart;
use Thelia\Model\CheckoutStep;
use Thelia\Model\ConfigQuery;

/**
 * Where each step of the checkout is served, and where a cart is sent when it asks for
 * one it has not got to yet.
 *
 * The one place that knows which route shows which step. Before it, every template and
 * every action named its own — `path('checkout_delivery')` written out in four files —
 * and a shop that turned the delivery step off had four of them pointing at a screen
 * that no longer belonged to its tunnel.
 *
 * It also knows how the shop lays the tunnel out, because that changes the answers: on a
 * one-page checkout every step is the same screen, so the routes of the steps in between
 * lead back to it.
 */
final readonly class CheckoutStepRouteResolver
{
    /**
     * The steps this theme has a screen of its own for.
     *
     * A step a module declared is not in here: it is served by the shared `checkout_step`
     * route when it names the component that draws it ({@see CheckoutModuleStepScreen}),
     * and the navigation walks past it rather than through it when it does not
     * ({@see navigableCodesOf()}).
     */
    private const ROUTES = [
        CheckoutStep::CODE_CART => 'checkout_cart',
        CheckoutStep::CODE_DELIVERY => 'checkout_delivery',
        CheckoutStep::CODE_PAYMENT => 'checkout_payment',
        CheckoutStep::CODE_CONFIRMATION => 'checkout_confirm',
    ];

    private const ENTRY_ROUTE = 'checkout_cart';

    private const PAY_ROUTE = 'checkout_pay';

    public function __construct(
        private CheckoutProgressionService $progression,
        private UrlGeneratorInterface $urlGenerator,
        private CheckoutModuleStepScreen $moduleStepScreen = new CheckoutModuleStepScreen(),
    ) {
    }

    public function displayMode(): CheckoutDisplayMode
    {
        return CheckoutDisplayMode::fromStoredValue(ConfigQuery::getCheckoutDisplayMode());
    }

    public function isOnePage(): bool
    {
        return CheckoutDisplayMode::OnePage === $this->displayMode();
    }

    public function routeFor(string $stepCode): string
    {
        // One page: the cart route serves the whole tunnel, so every step of it is that
        // route. The confirmation is not on the page — it is read once the order exists
        // — and keeps a route of its own.
        if ($this->isOnePage()) {
            return CheckoutStep::CODE_CONFIRMATION === $stepCode
                ? self::ROUTES[CheckoutStep::CODE_CONFIRMATION]
                : self::ENTRY_ROUTE;
        }

        // A step with no screen here is answered with the start of the tunnel rather
        // than with a 404: the buyer lands where the progression takes over and is
        // walked forward from there, which is also what happens to a step the merchant
        // turned off.
        return self::ROUTES[$stepCode] ?? self::ENTRY_ROUTE;
    }

    public function pathFor(string $stepCode): string
    {
        // A step a module declared is served by the one route they share, told apart by its code. Whether this
        // theme can draw it is asked by the navigation (navigableCodesOf), and by the route itself, which sends
        // a buyer back to the tunnel for a step it cannot serve.
        if (!$this->isOnePage()) {
            [$route, $parameters] = $this->moduleStepScreen->locate($stepCode, self::ROUTES, self::ENTRY_ROUTE);

            return $this->urlGenerator->generate($route, $parameters);
        }

        return $this->urlGenerator->generate($this->routeFor($stepCode));
    }

    /**
     * Where a cart goes when it asked for a step it has not got to.
     *
     * The progression is dropped first: this is only ever asked after something
     * happened — a step refusing the cart, an order turned away at the placement — so
     * answering from the memo of a moment ago would send the buyer to the step they
     * have just settled.
     *
     * @throws PropelException
     */
    public function pathOfTheFirstIncompleteStep(Cart $cart): string
    {
        $this->progression->forget();

        $firstIncomplete = $this->progression->firstIncompleteStep($cart);

        // Nothing left to do and still asked where to go: the tunnel is walked from its
        // first step, which is the one screen that is always there to land on.
        if (null === $firstIncomplete) {
            return $this->pathFor(CheckoutStep::CODE_CART);
        }

        return $this->pathFor($this->navigableCodeAtOrBefore($cart, $firstIncomplete->code));
    }

    /**
     * Where the "next" button of a step leads.
     *
     * The last step the buyer has something to do at is left by placing the order, not
     * by walking on to another screen: the confirmation is what they read once that has
     * gone through.
     *
     * A step with no screen in this theme is never what it answers, see
     * {@see navigableCodesOf()}.
     *
     * @throws PropelException
     */
    public function pathAfter(Cart $cart, string $stepCode): string
    {
        $next = $this->codeAfter($cart, $stepCode);

        if (null === $next || CheckoutStep::CODE_CONFIRMATION === $next) {
            return $this->urlGenerator->generate(self::PAY_ROUTE);
        }

        return $this->pathFor($next);
    }

    /**
     * The step this cart reaches after that one, or null when there is none.
     *
     * What the identification page asks: it is not a step of the tunnel, it stands in
     * front of the one that follows the cart, and it has to know which that is before it
     * can name it or send the buyer on to it.
     *
     * @throws PropelException
     */
    public function codeAfter(Cart $cart, string $stepCode): ?string
    {
        $codes = $this->navigableCodesOf($cart);
        $position = array_search($stepCode, $codes, true);

        return false === $position ? null : ($codes[$position + 1] ?? null);
    }

    /**
     * Where the "previous step" link of a step leads, or null on the step the tunnel
     * opens on.
     *
     * @throws PropelException
     */
    public function pathBefore(Cart $cart, string $stepCode): ?string
    {
        $codes = $this->navigableCodesOf($cart);
        $position = array_search($stepCode, $codes, true);

        if (false === $position || 0 === $position) {
            return null;
        }

        return $this->pathFor($codes[$position - 1]);
    }

    /**
     * The step the order is placed from: the last one the buyer has something to do at.
     *
     * A module adding a step between the payment and the confirmation becomes that one,
     * and the order button then waits for it too.
     *
     * @throws PropelException
     */
    public function lastStepToSettle(Cart $cart): string
    {
        $codes = array_values(array_filter(
            $this->codesOf($cart),
            static fn (string $code): bool => CheckoutStep::CODE_CONFIRMATION !== $code,
        ));

        // The payment is one of the steps a checkout is refused without, so the list is
        // never empty in practice; the fallback is there so that a configuration the
        // progression had to repair does not leave the order button without a step.
        return [] === $codes ? CheckoutStep::CODE_PAYMENT : $codes[\count($codes) - 1];
    }

    /**
     * The steps the "next" and "previous step" links may actually land on.
     *
     * On a checkout of one screen per step, a step this theme has no screen for is walked
     * past instead of walked to. Sent to it, the buyer would be answered with the start of
     * the tunnel — and the cart's own "next" link would point straight back at that step,
     * which is a loop between two pages rather than a checkout. The step is still in the
     * bar, still in the progression, and still checked at the placement: what it has not
     * got is a screen of its own here, and a theme that gives it one takes it back.
     *
     * On one page there is nothing to skip: every step of the tunnel is the screen the
     * buyer is already on.
     *
     * @return list<string>
     *
     * @throws PropelException
     */
    private function navigableCodesOf(Cart $cart): array
    {
        if ($this->isOnePage()) {
            return $this->codesOf($cart);
        }

        return $this->moduleStepScreen->navigableCodes($this->progression->activeSteps($cart), array_keys(self::ROUTES));
    }

    /**
     * That step when this theme serves a screen for it, and otherwise the last step
     * before it that it does.
     *
     * A step a module declared with no component to draw it has no screen here, so its
     * check applies to the navigation and to the placement and not to a page the buyer is
     * shown. Sent to the start of the tunnel instead, the buyer would be walked straight
     * back down to that same step by the "next" links, a loop between two pages.
     *
     * @throws PropelException
     */
    private function navigableCodeAtOrBefore(Cart $cart, string $stepCode): string
    {
        return $this->moduleStepScreen->landingFor($stepCode, $this->progression->activeSteps($cart), array_keys(self::ROUTES), CheckoutStep::CODE_CART);
    }

    /**
     * @return list<string>
     *
     * @throws PropelException
     */
    private function codesOf(Cart $cart): array
    {
        return array_map(
            static fn (CheckoutStepView $step): string => $step->code,
            $this->progression->activeSteps($cart),
        );
    }
}
