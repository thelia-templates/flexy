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

/**
 * The words a page uses to say what a bundle holds: a title and a quantity per component, nothing
 * else. A cart line and an order line both end up as the same list, so the one molecule that prints
 * it (Molecules:BundleIncluded) does not care where it came from.
 *
 * Quantities are said for ONE bundle, the way the merchant composed it: the quantity of the line
 * itself is printed beside it by the page.
 */
final class BundleLines
{
    /**
     * The composition a stored cart line holds, as the JSON the core wrote; null for any other line, and for a line of
     * a model that predates the bundles (no such column, so no such accessor: Propel answers an unknown method with an
     * exception, not with null).
     */
    public static function storedComposition(object $cartLine): ?string
    {
        if (!method_exists($cartLine, 'getBundleComposition')) {
            return null;
        }

        $composition = $cartLine->getBundleComposition();

        return \is_string($composition) && '' !== $composition ? $composition : null;
    }

    /**
     * A decimal quantity as a shopper reads it: no trailing zeros, no trailing point.
     */
    public static function formatQuantity(float|int|string $quantity): string
    {
        if (\is_string($quantity) && !is_numeric($quantity)) {
            return '0';
        }

        $formatted = rtrim(rtrim(number_format((float) $quantity, 6, '.', ''), '0'), '.');

        return '' === $formatted || '-0' === $formatted ? '0' : $formatted;
    }

    /**
     * The components a cart line carries, as the core publishes them under `bundle_components` of
     * `attr('cart', 'cart_items')`: only what the page prints is kept, so that the list is cheap to
     * serialize into a LiveComponent.
     *
     * @param mixed $components the value found under the key, anything when the core predates the feature
     *
     * @return list<array{title: string, quantity: string}>
     */
    public static function fromCartComponents(mixed $components): array
    {
        if (!\is_array($components)) {
            return [];
        }

        $lines = [];

        foreach ($components as $component) {
            if (!\is_array($component)) {
                continue;
            }

            $title = trim((string) ($component['title'] ?? ''));

            if ('' === $title) {
                continue;
            }

            $lines[] = ['title' => $title, 'quantity' => self::formatQuantity($component['quantity'] ?? 1)];
        }

        return $lines;
    }

    /**
     * The components of a bundle line of an order, from the lines the order stored. Each stores the
     * quantity of the whole line (one bundle holds 2 helmets, three bundles bought: 6), divided back
     * here to the quantity of one bundle, which is what the cart said.
     *
     * @param array<string, mixed>       $bundleLine
     * @param list<array<string, mixed>> $componentLines
     *
     * @return list<array{title: string, quantity: string}>
     */
    public static function fromOrderComponents(array $bundleLine, array $componentLines): array
    {
        $bundleQuantity = (float) ($bundleLine['quantity'] ?? 1);
        $divisor = $bundleQuantity > 0.0 ? $bundleQuantity : 1.0;
        $lines = [];

        foreach ($componentLines as $component) {
            $lines[] = [
                'title' => (string) ($component['title'] ?? ''),
                'quantity' => self::formatQuantity((float) ($component['quantity'] ?? 0) / $divisor),
            ];
        }

        return $lines;
    }
}
