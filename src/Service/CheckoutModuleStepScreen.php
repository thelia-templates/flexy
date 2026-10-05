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

use Thelia\Domain\Checkout\DTO\CheckoutStepView;

/**
 * What it takes for this theme to serve a screen for a step a module declared.
 *
 * The step names the component that draws it (`CheckoutStepProviderInterface::componentName()`), and the theme
 * serves it on one route shared by every such step, `checkout_step`, told apart by the code. A step that names no
 * component is one the theme cannot draw: it stays without a screen and the navigation walks past it.
 */
final readonly class CheckoutModuleStepScreen
{
    public const ROUTE = 'checkout_step';

    /** The shape of a step code in the url: the module code as a prefix, lower case, digits and underscores. */
    public const CODE_PATTERN = '[a-z0-9_]+';

    /**
     * @param list<string> $coreCodes the steps whose screens the theme has of its own
     */
    public function hasScreen(CheckoutStepView $step, array $coreCodes): bool
    {
        if (\in_array($step->code, $coreCodes, true)) {
            return true;
        }

        return null !== $step->componentName && '' !== $step->componentName && $this->isServable($step->code);
    }

    /**
     * The codes the "next" and "previous" links may land on, in the order of the tunnel.
     *
     * @param list<CheckoutStepView> $steps     the active steps of the cart, in order
     * @param list<string>           $coreCodes
     *
     * @return list<string>
     */
    public function navigableCodes(array $steps, array $coreCodes): array
    {
        return array_values(array_map(
            static fn (CheckoutStepView $step): string => $step->code,
            array_filter($steps, fn (CheckoutStepView $step): bool => $this->hasScreen($step, $coreCodes)),
        ));
    }

    /**
     * Where to land for a step: that step when it has a screen, otherwise the last step before it that has one, and the
     * fallback when none does. A step without a screen is never the answer: sent to it, the buyer would be walked
     * straight back to it by the "next" links.
     *
     * @param list<CheckoutStepView> $steps
     * @param list<string>           $coreCodes
     */
    public function landingFor(string $stepCode, array $steps, array $coreCodes, string $fallback): string
    {
        $navigable = $this->navigableCodes($steps, $coreCodes);

        if (\in_array($stepCode, $navigable, true)) {
            return $stepCode;
        }

        $codes = array_map(static fn (CheckoutStepView $step): string => $step->code, $steps);
        $position = array_search($stepCode, $codes, true);

        if (false === $position) {
            return $fallback;
        }

        foreach (array_reverse(\array_slice($codes, 0, $position)) as $code) {
            if (\in_array($code, $navigable, true)) {
                return $code;
            }
        }

        return $fallback;
    }

    /**
     * The route of a step and its parameters: the route of a core step, the shared route of a module step, the entry
     * route for a code that cannot travel in a url.
     *
     * @param array<string, string> $coreRoutes route by core code
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public function locate(string $stepCode, array $coreRoutes, string $entryRoute): array
    {
        if (isset($coreRoutes[$stepCode])) {
            return [$coreRoutes[$stepCode], []];
        }

        return $this->isServable($stepCode) ? [self::ROUTE, ['code' => $stepCode]] : [$entryRoute, []];
    }

    public function isServable(string $code): bool
    {
        return 1 === preg_match('/^'.self::CODE_PATTERN.'$/', $code);
    }
}
