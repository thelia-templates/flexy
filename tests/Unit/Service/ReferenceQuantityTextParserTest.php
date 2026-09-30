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

namespace FlexyBundle\Tests\Unit\Service;

use FlexyBundle\Exception\ReferenceQuantityTextRefusedException;
use FlexyBundle\Service\ReferenceQuantityTextParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReferenceQuantityTextParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function separators(): iterable
    {
        yield 'cells copied from a spreadsheet' => ["VIS-M6\t3\nECROU-M6\t12"];
        yield 'semicolons' => ["VIS-M6;3\nECROU-M6;12"];
        yield 'commas' => ["VIS-M6,3\nECROU-M6,12"];
        yield 'Windows line endings' => ["VIS-M6;3\r\nECROU-M6;12\r\n"];
    }

    #[DataProvider('separators')]
    public function testEverySeparatorASpreadsheetWritesIsRead(string $text): void
    {
        $parsed = (new ReferenceQuantityTextParser())->parse($text);

        self::assertSame([
            ['reference' => 'VIS-M6', 'quantity' => 3],
            ['reference' => 'ECROU-M6', 'quantity' => 12],
        ], $parsed->rows);
        self::assertSame([], $parsed->rejected);
    }

    public function testSixPastedLinesAreAllRecognised(): void
    {
        $text = "A-1;1\nA-2;2\nA-3;3\nA-4;4\nA-5;5\nA-6;6";

        self::assertCount(6, (new ReferenceQuantityTextParser())->parse($text)->rows);
    }

    public function testAHeaderBlankLinesAndQuotedCellsAreTolerated(): void
    {
        $text = "\u{FEFF}Référence;Quantité\n\n\"VIS-M6\";\"3\"\n   \nECROU-M6 ; 12 ;\n";

        $parsed = (new ReferenceQuantityTextParser())->parse($text);

        self::assertSame([
            ['reference' => 'VIS-M6', 'quantity' => 3],
            ['reference' => 'ECROU-M6', 'quantity' => 12],
        ], $parsed->rows);
        self::assertSame([], $parsed->rejected);
    }

    public function testALineThatCannotBeReadComesBackWithItsNumberAndReason(): void
    {
        $text = "VIS-M6;3\nECROU-M6;douze\nRONDELLE-M6\nCLOU;0\nVIS-M8;2;4\nVIS-M10;5";

        $parsed = (new ReferenceQuantityTextParser())->parse($text);

        self::assertSame([
            ['reference' => 'VIS-M6', 'quantity' => 3],
            ['reference' => 'VIS-M10', 'quantity' => 5],
        ], $parsed->rows);
        self::assertSame([
            ['line' => 2, 'content' => 'ECROU-M6;douze', 'reason' => ReferenceQuantityTextParser::REASON_QUANTITY],
            ['line' => 3, 'content' => 'RONDELLE-M6', 'reason' => ReferenceQuantityTextParser::REASON_COLUMNS],
            ['line' => 4, 'content' => 'CLOU;0', 'reason' => ReferenceQuantityTextParser::REASON_QUANTITY],
            ['line' => 5, 'content' => 'VIS-M8;2;4', 'reason' => ReferenceQuantityTextParser::REASON_COLUMNS],
        ], $parsed->rejected);
    }

    public function testAReferenceLongerThanTheCoreTakesIsRefusedAloneRatherThanWithTheTable(): void
    {
        $parsed = (new ReferenceQuantityTextParser())->parse("VIS-M6;3\n".str_repeat('X', 256).';1');

        self::assertSame([['reference' => 'VIS-M6', 'quantity' => 3]], $parsed->rows);
        self::assertSame([2], array_column($parsed->rejected, 'line'));
        self::assertSame(ReferenceQuantityTextParser::REASON_REFERENCE, $parsed->rejected[0]['reason']);
    }

    public function testOnlyTheFirstLineMayBeAHeader(): void
    {
        $parsed = (new ReferenceQuantityTextParser())->parse("VIS-M6;3\nReference;Quantity");

        self::assertCount(1, $parsed->rows);
        self::assertSame(ReferenceQuantityTextParser::REASON_QUANTITY, $parsed->rejected[0]['reason']);
    }

    public function testATableFullOfRowsIsTaken(): void
    {
        $text = implode("\n", array_map(static fn (int $n): string => 'REF-'.$n.';1', range(1, ReferenceQuantityTextParser::MAX_LINES)));

        self::assertCount(ReferenceQuantityTextParser::MAX_LINES, (new ReferenceQuantityTextParser())->parse($text)->rows);
    }

    public function testOneRowMoreThanATableTakesRefusesTheWholeText(): void
    {
        $text = implode("\n", array_map(static fn (int $n): string => 'REF-'.$n.';1', range(1, ReferenceQuantityTextParser::MAX_LINES + 1)));

        $this->expectException(ReferenceQuantityTextRefusedException::class);

        (new ReferenceQuantityTextParser())->parse($text);
    }

    public function testATextLongerThanTheBoundIsRefusedBeforeBeingRead(): void
    {
        $this->expectException(ReferenceQuantityTextRefusedException::class);

        (new ReferenceQuantityTextParser())->parse(str_repeat('A', ReferenceQuantityTextParser::MAX_BYTES + 1));
    }
}
