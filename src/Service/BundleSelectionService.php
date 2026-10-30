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
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Catalog\Bundle\BundleComposition;
use Thelia\Domain\Catalog\Bundle\BundleCompositionResolver;
use Thelia\Domain\Catalog\Bundle\Exception\InvalidBundleCompositionException;
use Thelia\Domain\Pricing\BundleQuoter;
use Thelia\Model\Currency;

/**
 * What a shopper's choices on a bundle sheet come to: the composition the core accepts, and what it
 * costs.
 *
 * The theme holds no rule of its own here. The composition is the one `BundleCompositionResolver`
 * validates (bounds, required components, sellable components), and the prices are the ones the core
 * quotes for it (`BundleQuoter`: settled like the cart line, customer discount and tax of the product
 * of the bundle included, struck price and saving too). Nothing is computed here, only picked.
 */
final readonly class BundleSelectionService
{
    public function __construct(
        private BundleCompositionResolver $resolver,
        private BundleQuoter $quoter,
        private RequestStack $requestStack,
        private SecurityContext $securityContext,
    ) {
    }

    /**
     * The composition a sheet stands on: the default one while the shopper changed nothing, else the
     * default one with the shopper's quantities laid over it. A choice the core refuses (a tampered
     * request, a bound that moved since the page was drawn) falls back to the default composition
     * rather than a page that cannot be drawn.
     *
     * The composition is null when not even the default one can be sold (a required component is
     * gone); the refusal is the sentence of the core when the shopper's choice was refused and the
     * default composition stands in.
     *
     * @param list<array{itemId: int}> $rows      the components of the sheet
     * @param array<mixed>             $overrides the shopper's quantities by bundle item id, only the ones that differ from the default
     *
     * @return array{composition: BundleComposition|null, fellBack: bool, refusal: string|null}
     */
    public function compose(int $bundleProductId, array $rows, array $overrides): array
    {
        try {
            $default = $this->resolver->resolve($bundleProductId, null);
        } catch (InvalidBundleCompositionException) {
            return ['composition' => null, 'fellBack' => false, 'refusal' => null];
        }

        // A quantity equal to the default one is not a change: a field that holds its default value (the sheet binds
        // one key per quantity field, see defaultQuantities) leaves the composition, and so the price, as it was.
        $overrides = array_filter(
            self::sanitize($overrides, $rows),
            static fn (string $quantity, int $itemId): bool => BundleLines::formatQuantity($quantity) !== BundleLines::formatQuantity($default->quantityOf($itemId)),
            \ARRAY_FILTER_USE_BOTH,
        );

        if ([] === $overrides) {
            return ['composition' => $default, 'fellBack' => false, 'refusal' => null];
        }

        $lines = [];

        foreach ($rows as $row) {
            $lines[] = [
                'bundleItemId' => $row['itemId'],
                'quantity' => $overrides[$row['itemId']] ?? $default->quantityOf($row['itemId']),
            ];
        }

        try {
            return ['composition' => $this->resolver->resolve($bundleProductId, BundleComposition::fromArray($lines)), 'fellBack' => false, 'refusal' => null];
        } catch (InvalidBundleCompositionException $exception) {
            return ['composition' => $default, 'fellBack' => true, 'refusal' => trim($exception->getMessage())];
        }
    }

    /**
     * The quantity of every component in the default composition, by bundle item id, as a shopper reads it.
     * What a quantity field starts at: the sheet keeps one key per field in its state, since the browser can
     * only write a model that exists there.
     *
     * @param list<array{itemId: int, quantity: string}> $rows the components of the sheet
     *
     * @return array<int, string>
     */
    public function defaultQuantities(int $bundleProductId, array $rows): array
    {
        try {
            $default = $this->resolver->resolve($bundleProductId, null);
        } catch (InvalidBundleCompositionException) {
            $default = null;
        }

        $quantities = [];

        foreach ($rows as $row) {
            $quantities[$row['itemId']] = BundleLines::formatQuantity($default?->quantityOf($row['itemId']) ?? $row['quantity']);
        }

        return $quantities;
    }

    /**
     * The price of a composition, taxes included, for a bundle priced by its components; null for a
     * bundle in fixed mode, which is priced like any product by its own sale element.
     *
     * `originalPrice` and `saving` are null when the bundle gives no discount, or when the shop asks not to
     * show the price it would cost without.
     *
     * @return array{price: float, originalPrice: float|null, saving: float|null}|null
     */
    public function quote(int $bundleProductId, int $saleElementId, BundleComposition $composition): ?array
    {
        $customer = $this->securityContext->hasCustomerUser() ? $this->securityContext->getCustomerUser() : null;
        $quote = $this->quoter->quote($bundleProductId, $saleElementId, $composition, $this->currency(), $customer);

        if (null === $quote) {
            return null;
        }

        return ['price' => $quote->taxedPrice, 'originalPrice' => $quote->taxedFullPrice, 'saving' => $quote->taxedSaving];
    }

    /**
     * Only a quantity for a component of the sheet is kept: what the browser sends back is a number
     * for an id, and anything else is not read. Whether the number is allowed is the core's to say.
     *
     * @param array<mixed>             $overrides
     * @param list<array{itemId: int}> $rows
     *
     * @return array<int, string>
     */
    private static function sanitize(array $overrides, array $rows): array
    {
        $known = array_column($rows, 'itemId');
        $kept = [];

        foreach ($overrides as $itemId => $quantity) {
            if (!\in_array((int) $itemId, $known, true) || !(\is_string($quantity) || \is_int($quantity) || \is_float($quantity)) || !is_numeric($quantity)) {
                continue;
            }

            $kept[(int) $itemId] = (string) $quantity;
        }

        return $kept;
    }

    private function currency(): Currency
    {
        $session = $this->requestStack->getCurrentRequest()?->hasSession() ? $this->requestStack->getSession() : null;

        return $session instanceof Session ? $session->getCurrency() : Currency::getDefaultCurrency();
    }
}
