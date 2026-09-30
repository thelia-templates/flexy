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

use FlexyBundle\DTO\ParsedReferenceQuantities;
use FlexyBundle\Exception\ReferenceQuantityTextRefusedException;
use Thelia\Domain\Catalog\DTO\ReferenceQuantityLines;

/**
 * Reads cells copied from a spreadsheet, or the text of a CSV file the browser has
 * already read, into reference and quantity rows.
 *
 * One line is one row: the reference first, the quantity second, separated by a tab
 * (what a spreadsheet copies), a semicolon or a comma. Blank lines are skipped, and a
 * first line whose quantity is not a number is taken for a header. A line that cannot
 * be read is not dropped silently: it comes back with its number so the page can say
 * which one to fix. A reference longer than the core takes is refused here, line by
 * line: left to the core, it would refuse the whole table. Cleaning the reference
 * itself is left to the core, which strips invisible characters and extra spaces the
 * same way for every source.
 */
final readonly class ReferenceQuantityTextParser
{
    public const int MAX_BYTES = 100_000;

    public const int MAX_LINES = ReferenceQuantityLines::MAX_LINES;

    public const string REASON_COLUMNS = 'columns';

    public const string REASON_QUANTITY = 'quantity';

    public const string REASON_REFERENCE = 'reference';

    /**
     * @throws ReferenceQuantityTextRefusedException when the text is longer or has more rows than a table takes
     */
    public function parse(string $text): ParsedReferenceQuantities
    {
        if (\strlen($text) > self::MAX_BYTES) {
            throw ReferenceQuantityTextRefusedException::tooLarge(self::MAX_BYTES);
        }

        $text = preg_replace('/^\x{FEFF}/u', '', $text) ?? $text;
        $rows = [];
        $rejected = [];
        $headerChecked = false;

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $index => $line) {
            if ('' === trim($line)) {
                continue;
            }

            $cells = array_values(array_filter(
                array_map(static fn (?string $cell): string => trim((string) $cell), str_getcsv($line, self::separatorOf($line), '"', '')),
                static fn (string $cell): bool => '' !== $cell,
            ));
            $isFirstLine = !$headerChecked;
            $headerChecked = true;

            if (2 !== \count($cells)) {
                $rejected[] = ['line' => $index + 1, 'content' => $line, 'reason' => self::REASON_COLUMNS];

                continue;
            }

            [$reference, $quantity] = $cells;

            if (1 !== preg_match('/^[1-9]\d{0,8}$/', $quantity)) {
                if (!$isFirstLine) {
                    $rejected[] = ['line' => $index + 1, 'content' => $line, 'reason' => self::REASON_QUANTITY];
                }

                continue;
            }

            if (mb_strlen($reference) > ReferenceQuantityLines::MAX_REFERENCE_LENGTH) {
                $rejected[] = ['line' => $index + 1, 'content' => mb_substr($line, 0, 80).'…', 'reason' => self::REASON_REFERENCE];

                continue;
            }

            if (\count($rows) === self::MAX_LINES) {
                throw ReferenceQuantityTextRefusedException::tooManyLines(self::MAX_LINES);
            }

            $rows[] = ['reference' => $reference, 'quantity' => (int) $quantity];
        }

        return new ParsedReferenceQuantities($rows, $rejected);
    }

    private static function separatorOf(string $line): string
    {
        return match (true) {
            str_contains($line, "\t") => "\t",
            str_contains($line, ';') => ';',
            default => ',',
        };
    }
}
