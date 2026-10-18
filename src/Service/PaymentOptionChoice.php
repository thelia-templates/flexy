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
 * The options of a payment module the buyer has picked, after one more answer.
 *
 * A payment module offers its options through the core's MODULE_PAYMENT_GET_OPTIONS
 * event ("pay in three instalments", "use the saved card"), grouped, each group saying
 * how many of its options may be picked. The payment step sends the group and option
 * codes from the browser: only an option the module offers for this cart is kept, and
 * a group never holds more options than it allows. A group that allows one option
 * swaps the picked one for the new one.
 *
 * The choices have the shape of the core's PaymentModuleOptionChoice: one entry per
 * group, with the option codes picked in it.
 *
 * A decision and nothing else, so that it can be read and tested on its own.
 */
final readonly class PaymentOptionChoice
{
    /**
     * @param array<array-key, array<string, mixed>>                     $offeredGroups the optionGroups of the payment module, as the payment API answers them
     * @param list<array{group: string, values: list<string>}>           $choices       what the buyer has picked so far
     *
     * @return list<array{group: string, values: list<string>}>
     */
    public static function answer(array $offeredGroups, array $choices, string $groupCode, string $optionCode, bool $chosen): array
    {
        $group = self::offeredGroup($offeredGroups, $groupCode);

        if (null === $group || !self::offersOption($group, $optionCode)) {
            return self::keepOffered($offeredGroups, $choices);
        }

        $picked = [];

        foreach ($choices as $choice) {
            if ($choice['group'] === $groupCode) {
                $picked = $choice['values'];
            }
        }

        $picked = array_values(array_filter($picked, static fn (string $code): bool => $code !== $optionCode));

        if ($chosen) {
            $maximum = isset($group['maximumSelectedOptions']) ? (int) $group['maximumSelectedOptions'] : null;

            if (1 === $maximum) {
                $picked = [];
            }

            if (null === $maximum || $maximum <= 0 || \count($picked) < $maximum) {
                $picked[] = $optionCode;
            }
        }

        $answered = array_values(array_filter($choices, static fn (array $choice): bool => $choice['group'] !== $groupCode));

        if ([] !== $picked) {
            $answered[] = ['group' => $groupCode, 'values' => $picked];
        }

        return self::keepOffered($offeredGroups, $answered);
    }

    /**
     * @param array<array-key, array<string, mixed>>           $offeredGroups
     * @param list<array{group: string, values: list<string>}> $choices
     *
     * @return list<array{group: string, values: list<string>}>
     */
    public static function keepOffered(array $offeredGroups, array $choices): array
    {
        $kept = [];

        foreach ($choices as $choice) {
            $group = self::offeredGroup($offeredGroups, $choice['group']);

            if (null === $group) {
                continue;
            }

            $values = array_values(array_filter(
                $choice['values'],
                static fn (string $code): bool => self::offersOption($group, $code),
            ));

            if ([] !== $values) {
                $kept[] = ['group' => $choice['group'], 'values' => $values];
            }
        }

        return $kept;
    }

    /**
     * @param array<array-key, array<string, mixed>> $offeredGroups
     *
     * @return array<string, mixed>|null
     */
    private static function offeredGroup(array $offeredGroups, string $groupCode): ?array
    {
        foreach ($offeredGroups as $group) {
            if (($group['code'] ?? null) === $groupCode) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $group
     */
    private static function offersOption(array $group, string $optionCode): bool
    {
        foreach ($group['options'] ?? [] as $option) {
            if (($option['code'] ?? null) === $optionCode && ($option['valid'] ?? true)) {
                return true;
            }
        }

        return false;
    }
}
