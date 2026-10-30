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

namespace FlexyBundle\DTO;

use Thelia\Domain\Catalog\Quantity\UnitCode;

/**
 * The quantities a sale element is sold in, as the theme reads them to draw its selector:
 * a minimum, a step the quantity moves by, the decimals it accepts, and the unit it is
 * measured in. The keys are those of the front API (`minQuantity`, `quantityStep`,
 * `quantityDecimals`, `unitQuantity`, `referenceUnitQuantity`, `unitCode`).
 *
 * The selector is a help, not a check: the cart judges every quantity by the rules of the
 * core. A core that predates these rules sends none of the keys, and every sale element
 * then reads as sold by the piece, one at a time, which is what such a core accepts.
 */
final readonly class QuantityRuleDTO
{
    /** The longest list of quantities the selector offers when nothing else bounds it. */
    public const LIST_LENGTH = 50;

    /** Mirrors the core: a quantity is sold with three decimals at most. */
    private const MAXIMUM_DECIMALS = 3;

    public function __construct(
        public float $minQuantity = 1.0,
        public float $step = 1.0,
        public int $decimals = 0,
        public ?float $unitQuantity = null,
        public ?float $referenceUnitQuantity = null,
        public ?string $unitCode = null,
    ) {
    }

    /**
     * A value that is missing, or that no shop could have saved (a step of zero), reads as
     * the default: a selector that offers nothing would leave the product unbuyable.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $decimals = is_numeric($data['quantityDecimals'] ?? null) ? (int) $data['quantityDecimals'] : 0;

        return new self(
            minQuantity: self::positive($data['minQuantity'] ?? null) ?? 1.0,
            step: self::positive($data['quantityStep'] ?? null) ?? 1.0,
            decimals: max(0, min(self::MAXIMUM_DECIMALS, $decimals)),
            unitQuantity: self::positive($data['unitQuantity'] ?? null),
            referenceUnitQuantity: self::positive($data['referenceUnitQuantity'] ?? null),
            unitCode: \is_string($data['unitCode'] ?? null) && '' !== $data['unitCode'] ? $data['unitCode'] : null,
        );
    }

    /**
     * The same keys fromArray() reads, so that a rule travels in a LiveProp array and comes
     * back unchanged.
     *
     * @return array{minQuantity: float, quantityStep: float, quantityDecimals: int, unitQuantity: float|null, referenceUnitQuantity: float|null, unitCode: string|null}
     */
    public function toArray(): array
    {
        return [
            'minQuantity' => $this->minQuantity,
            'quantityStep' => $this->step,
            'quantityDecimals' => $this->decimals,
            'unitQuantity' => $this->unitQuantity,
            'referenceUnitQuantity' => $this->referenceUnitQuantity,
            'unitCode' => $this->unitCode,
        ];
    }

    public function isSoldByMeasure(): bool
    {
        return $this->decimals > 0;
    }

    /**
     * A whole step above one: the quantities are few and far apart, and the selector lists
     * them rather than letting the shopper type one the cart would refuse.
     */
    public function offersAList(): bool
    {
        return 0 === $this->decimals && $this->scaledStep() > 1;
    }

    /**
     * The first multiple of the step that is not below the minimum: 6 for a minimum of 2
     * sold by 6. Same arithmetic as the core's QuantityRule::smallestQuantity(), on whole
     * numbers scaled by the decimals, so that 0.1 + 0.2 never misses 0.3.
     */
    public function smallestQuantity(): float
    {
        return $this->unscaled($this->smallestScaledQuantity());
    }

    /**
     * The quantities the cart accepts, from the smallest one up to the limit (the stock left),
     * and no more than $count of them.
     *
     * @return list<float>
     */
    public function validQuantitiesUpTo(float $limit, int $count = self::LIST_LENGTH): array
    {
        $quantities = [];
        $step = $this->scaledStep();
        $last = $this->scaledFloor($limit);

        for ($quantity = $this->smallestScaledQuantity(); $quantity <= $last && \count($quantities) < $count; $quantity += $step) {
            $quantities[] = $this->unscaled($quantity);
        }

        return $quantities;
    }

    /**
     * The largest quantity the cart accepts that does not exceed the limit, or null when even
     * the smallest one does.
     */
    public function largestValidQuantityUpTo(float $limit): ?float
    {
        $smallest = $this->smallestScaledQuantity();
        $last = $this->scaledFloor($limit);

        if ($last < $smallest) {
            return null;
        }

        $step = $this->scaledStep();

        return $this->unscaled($smallest + intdiv($last - $smallest, $step) * $step);
    }

    /**
     * The quantity one press of a button leads to: $steps steps up (positive) or down
     * (negative) from $quantity, in whole numbers scaled by the decimals, so that 1.25 less
     * a quarter lands on 1 and never on 0.99999.
     */
    public function moveBy(float $quantity, int $steps): float
    {
        return $this->unscaled((int) round($quantity * $this->scale()) + $steps * $this->scaledStep());
    }

    /**
     * The unit as it is written after a quantity ("kg", "m²"), by the core's UnitCode. A code
     * the core does not know, or a core without units, shows no unit: the unit only says what
     * the quantity counts, the cart never reads it.
     */
    public function unitSymbol(): ?string
    {
        if (null === $this->unitCode || !enum_exists(UnitCode::class)) {
            return null;
        }

        return UnitCode::tryFrom($this->unitCode)?->symbol();
    }

    /**
     * The step as an HTML `step` attribute: "0.25", "6".
     */
    public function inputStep(): string
    {
        return number_format($this->unscaled($this->scaledStep()), $this->decimals, '.', '');
    }

    /**
     * The quantity written the way an input holds it, with a point and the decimals of the
     * rule: "1.25", "6". The input is what the shopper edits, never a localized display.
     */
    public function inputValue(float $quantity): string
    {
        return number_format($quantity, $this->decimals, '.', '');
    }

    /**
     * The quantity written in a field the shopper reads and edits, in the language of the page
     * and with the decimals of the rule: "0,50" on a French page, "0.50" on an English one, "6"
     * for a count of pieces. The stepper's controller writes it the same way after each press,
     * so the field never changes its own writing under the shopper's eyes.
     */
    public function fieldValue(float $quantity, ?string $locale = null): string
    {
        if (0 === $this->decimals || null === $locale || !class_exists(\NumberFormatter::class)) {
            return $this->inputValue($quantity);
        }

        $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::GROUPING_USED, 0);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $this->decimals);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $this->decimals);

        $written = $formatter->format($quantity);

        return false === $written ? $this->inputValue($quantity) : $written;
    }

    private function scale(): int
    {
        return 10 ** $this->decimals;
    }

    private function scaledStep(): int
    {
        return max(1, (int) round($this->step * $this->scale()));
    }

    private function smallestScaledQuantity(): int
    {
        $step = $this->scaledStep();
        $minimum = max(1, (int) round($this->minQuantity * $this->scale()));

        return intdiv($minimum + $step - 1, $step) * $step;
    }

    /**
     * The limit in units of the last decimal, rounded down: a stock of 7.5 holds 7 pieces.
     * The epsilon absorbs a float stored as 2.9999999 for 3.
     */
    private function scaledFloor(float $limit): int
    {
        if ($limit >= \PHP_INT_MAX / $this->scale()) {
            return \PHP_INT_MAX - $this->scaledStep();
        }

        return (int) floor($limit * $this->scale() + 1e-6);
    }

    private function unscaled(int $quantity): float
    {
        return round($quantity / $this->scale(), $this->decimals);
    }

    private static function positive(mixed $value): ?float
    {
        if (!is_numeric($value) || (float) $value <= 0.0) {
            return null;
        }

        return (float) $value;
    }
}
