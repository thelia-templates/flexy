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

namespace FlexyBundle\Tests\Unit\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The theme chooses between a price with the taxes and a price without, and calculates nothing:
 * every amount it prints is one the core published, in both forms. This test reads the
 * places where an amount is handled (the services and the component classes: all of `src` and `components`) and fails
 * on any arithmetic applied to one: an operator, a rounding, a bound.
 *
 * An expression counts as an amount when its source names one (price, total, discount, tax,
 * postage, shipping, amount, cost). Arithmetic that is not on an amount (a counter, an index, a
 * page number) is not looked at. What is left, and legitimate, goes in ALLOWED below with the
 * reason: an entry is the exact expression of one file, so a new calculation of the same kind
 * elsewhere still fails.
 */
final class NoAmountArithmeticTest extends TestCase
{
    private const AMOUNT_WORDS = '/price|total|discount|tax|postage|shipping|amount|cost|fee|vat|offered/i';

    /**
     * Function calls that calculate: `round(` is the one the rule names, the others are its siblings.
     */
    private const CALCULATING_FUNCTIONS = ['round', 'floor', 'ceil', 'min', 'max', 'abs', 'array_sum', 'number_format', 'bcadd', 'bcsub', 'bcmul', 'bcdiv'];

    /**
     * Expressions allowed to calculate, by file relative to the theme: the expression as the
     * printer writes it, and why it is not a calculation of an amount.
     *
     * @var array<string, array<string, string>>
     */
    private const ALLOWED = [
        // A badge percentage is a ratio of two prices the core published, not an amount: the API publishes no rate,
        // and nothing printed in a currency comes out of it.
        'components/Organisms/ProductCard/Base.php' => [
            '($this->price - $this->promoPrice) / $this->price * -1' => 'Promotion badge percentage.',
        ],
        'components/Layouts/ProductDetails/Base.php' => [
            '-(($price - $promoPrice) / $price)' => 'Promotion badge percentage.',
        ],
        // Writing a decimal into a DECIMAL(16,6) field of the return as a string: it formats a figure the buyer typed or the
        // core read, with the six decimals of the column. Nothing is added, cut or bounded, and nothing printed comes out of it.
        'src/Service/InstantPaymentParts.php' => [
            "number_format((float) \$amount, 6, '.', '')" => 'Serialises an amount to the six decimals of the column, no calculation.',
        ],
        'src/Service/OrderReturnRequestService.php' => [
            "number_format(\$amount ?? 0.0, 6, '.', '')" => 'Serialises an amount to the six decimals of the column, no calculation.',
        ],
        // Page counts: the amount words these expressions contain are the total number of items, not an amount.
        'components/Layouts/AccountOrders/Base.php' => [
            'max(1, (int) ceil($totalItems / $itemsPerPage))' => 'Number of pages.',
        ],
        'components/Layouts/FolderContents/Base.php' => [
            'max(1, (int) ceil($totalItems / self::ITEMS_PER_PAGE))' => 'Number of pages.',
        ],
        'components/Layouts/ProductListing/Base.php' => [
            'max(1, (int) ceil($totalItems / self::ITEMS_PER_PAGE))' => 'Number of pages.',
        ],
        'components/Molecules/Pagination/Base.php' => [
            'ceil($this->totalItems / $this->itemsPerPage)' => 'Number of pages.',
            'max(1, min($this->currentPage, $totalPages))' => 'Page number.',
        ],
    ];

    public function testTheScannerSeesArithmeticOnAnAmount(): void
    {
        // Guards the guard: a scanner that finds nothing anywhere would be green for ever.
        $found = $this->calculationsOf(<<<'PHP'
            <?php
            $a = $total + $tax;
            $b = round($price * $quantity, 2);
            $c = min($discount, $total);
            $d = $page + 1;
            PHP);

        self::assertSame(['$total + $tax', 'round($price * $quantity, 2)', '$price * $quantity', 'min($discount, $total)'], $found);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function watchedFiles(): iterable
    {
        $themeDirectory = \dirname(__DIR__, 3);
        $files = [];

        foreach (['components', 'src'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($themeDirectory.'/'.$directory, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        foreach ($files as $file) {
            yield substr($file, \strlen($themeDirectory) + 1) => [$file];
        }
    }

    #[DataProvider('watchedFiles')]
    public function testTheFileCalculatesNoAmount(string $file): void
    {
        $relative = substr($file, \strlen(\dirname(__DIR__, 3)) + 1);
        $allowed = self::ALLOWED[$relative] ?? [];

        $found = $this->calculationsOf((string) file_get_contents($file), $allowed);

        self::assertSame([], $found, \sprintf(
            "%s calculates an amount the core should publish:\n  %s",
            $relative,
            implode("\n  ", $found),
        ));
    }

    public function testEveryAllowedExpressionIsStillThere(): void
    {
        // A whitelist entry that no longer matches anything is a hole left open for the next one.
        foreach (self::ALLOWED as $relative => $expressions) {
            $source = (string) file_get_contents(\dirname(__DIR__, 3).'/'.$relative);

            foreach (array_keys($expressions) as $expression) {
                self::assertContains($expression, $this->calculationsOf($source), \sprintf('%s no longer holds `%s`: drop it from ALLOWED.', $relative, $expression));
            }
        }
    }

    /**
     * @param array<string, string> $allowed expressions to leave out, with everything they contain
     *
     * @return list<string> the arithmetic applied to an amount, as the printer writes it
     */
    private function calculationsOf(string $source, array $allowed = []): array
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
        $printer = new Standard();
        $found = [];
        $skipUntil = -1;

        // Pre-order: an expression comes before what it contains, so one that is allowed closes
        // the whole range of the source it spans.
        foreach ((new NodeFinder())->find($statements, fn (Node $node): bool => $this->calculates($node)) as $node) {
            if ($node->getStartFilePos() <= $skipUntil) {
                continue;
            }

            $expression = $printer->prettyPrintExpr($node);

            if (1 !== preg_match(self::AMOUNT_WORDS, $expression)) {
                continue;
            }

            if (\array_key_exists($expression, $allowed)) {
                $skipUntil = $node->getEndFilePos();

                continue;
            }

            $found[] = $expression;
        }

        return $found;
    }

    private function calculates(Node $node): bool
    {
        if ($node instanceof BinaryOp\Plus || $node instanceof BinaryOp\Minus || $node instanceof BinaryOp\Mul || $node instanceof BinaryOp\Div || $node instanceof BinaryOp\Mod || $node instanceof BinaryOp\Pow) {
            return true;
        }

        if ($node instanceof AssignOp\Plus || $node instanceof AssignOp\Minus || $node instanceof AssignOp\Mul || $node instanceof AssignOp\Div) {
            return true;
        }

        if ($node instanceof UnaryMinus) {
            return true;
        }

        return $node instanceof FuncCall
            && $node->name instanceof Name
            && \in_array(strtolower($node->name->toString()), self::CALCULATING_FUNCTIONS, true);
    }
}
