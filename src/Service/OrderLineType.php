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
 * What kind of line an order line is, read from the payload of the front order resource (or from the
 * Propel array of a guest order, which carries the same keys).
 *
 * The core knows four kinds: a product, a service (a gift wrapping), the line of a bundle (the price
 * of the pack, nothing to ship) and the line of a component of a bundle (priced at zero, shipped,
 * hung on its bundle line by `parent`). The predicates below are the ones of the core
 * (`Thelia\Model\OrderProduct`), read from arrays: a payload that predates the feature carries no
 * `lineType` at all, and every line then reads as a product, which on such an order it is.
 */
final class OrderLineType
{
    public const string PRODUCT = 'product';
    public const string SERVICE = 'service';
    public const string BUNDLE = 'bundle';
    public const string BUNDLE_COMPONENT = 'bundle_component';

    /**
     * @param array<string, mixed> $line
     */
    public static function of(array $line): string
    {
        $type = $line['lineType'] ?? $line['line_type'] ?? null;

        return \is_string($type) && '' !== $type ? $type : self::PRODUCT;
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function isService(array $line): bool
    {
        return self::SERVICE === self::of($line);
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function isBundle(array $line): bool
    {
        return self::BUNDLE === self::of($line);
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function isBundleComponent(array $line): bool
    {
        return self::BUNDLE_COMPONENT === self::of($line);
    }

    /**
     * A parcel carries a product or a component, never a service and never the line of the bundle:
     * what the bundle stands for is shipped through its components.
     *
     * @param array<string, mixed> $line
     */
    public static function isShippable(array $line): bool
    {
        return !self::isService($line) && !self::isBundle($line);
    }

    /**
     * The id of the bundle line a component hangs on, null for any other line.
     *
     * @param array<string, mixed> $line
     */
    public static function parentOf(array $line): ?int
    {
        if (!self::isBundleComponent($line)) {
            return null;
        }

        $parent = $line['parent'] ?? null;

        // The front API may hand a relation over as an IRI or an object: only a bare id is read here.
        return is_numeric($parent) && (int) $parent > 0 ? (int) $parent : null;
    }

    /**
     * The lines of an order as a list of top-level entries, each bundle line followed by the
     * components that hang on it. Reads positions, not ids, so a caller that holds the lines in
     * another shape (resolved, with their product) can map the answer back on its own list.
     *
     * A component whose bundle line is not in the list is kept as an entry of its own, never dropped:
     * an order is read as it was stored.
     *
     * @param list<array<string, mixed>> $lines
     *
     * @return list<array{index: int, components: list<int>}>
     */
    public static function groupUnderBundles(array $lines): array
    {
        $bundleIndexById = [];

        foreach ($lines as $index => $line) {
            if (self::isBundle($line) && isset($line['id'])) {
                $bundleIndexById[(int) $line['id']] = $index;
            }
        }

        $componentsOf = [];
        $orphans = [];

        foreach ($lines as $index => $line) {
            if (!self::isBundleComponent($line)) {
                continue;
            }

            $parent = self::parentOf($line);

            if (null !== $parent && isset($bundleIndexById[$parent])) {
                $componentsOf[$bundleIndexById[$parent]][] = $index;
            } else {
                $orphans[$index] = true;
            }
        }

        $groups = [];

        foreach ($lines as $index => $line) {
            if (self::isBundleComponent($line) && !isset($orphans[$index])) {
                continue;
            }

            $groups[] = ['index' => $index, 'components' => $componentsOf[$index] ?? []];
        }

        return $groups;
    }
}
