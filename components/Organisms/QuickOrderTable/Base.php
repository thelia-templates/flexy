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

namespace FlexyBundle\Components\Organisms\QuickOrderTable;

use FlexyBundle\Exception\ReferenceQuantityTextRefusedException;
use FlexyBundle\Service\QuickOrderService;
use FlexyBundle\Service\ReferenceQuantityTextParser;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Catalog\DTO\ReferenceQuantity;
use Thelia\Domain\Catalog\DTO\ReferenceQuantityLines;
use Thelia\Domain\Catalog\Exception\InvalidReferenceQuantityException;
use Thelia\Domain\QuickOrder\DTO\QuickOrderTable;
use Thelia\Domain\QuickOrder\Enum\LineStatus;
use Thelia\Domain\QuickOrder\Exception\QuickOrderRateLimitedException;
use Thelia\Model\Customer;

/**
 * The quick order table: the rows the buyer types, pastes or imports, and what the shop
 * made of them the last time they were checked.
 *
 * Checking is always an explicit action (the button, or right after a paste or an
 * import): a check per field left would spend the account's quick order budget thirty
 * lines at a time. The result is kept in `lines`, a prop the browser can send back but
 * not change, so a re-render never reads the catalogue again, and the cart only ever
 * receives rows that this result called resolved and that were not edited since.
 *
 * Each row carries a key of its own, so that adding or removing one does not move the
 * focus or the values of the others on the next render.
 */
#[AsLiveComponent]
class Base
{
    use DefaultActionTrait;

    public const int EMPTY_ROWS = 1;

    /**
     * What the buyer typed. The sale element is the one a reference shared by several
     * of them was settled on; the core checks that it still carries the reference.
     *
     * @var list<array{key: string, reference: string, quantity: int|string, productSaleElementsId: int|string|null}>
     */
    #[LiveProp(writable: true)]
    public array $rows = [];

    /**
     * The control table of the last check, one entry per row, in the same order. A row
     * left out of the check (its quantity is not a whole number above zero) has none.
     *
     * @var list<array<string, mixed>|null>
     */
    #[LiveProp]
    public array $lines = [];

    #[LiveProp(writable: true)]
    public string $pastedText = '';

    /** @var list<array{line: int, content: string, reason: string}> */
    #[LiveProp]
    public array $rejectedLines = [];

    public ?string $error = null;

    public int $addedCount = 0;

    public function __construct(
        private readonly QuickOrderService $quickOrderService,
        private readonly ReferenceQuantityTextParser $parser,
        private readonly SecurityContext $securityContext,
    ) {
    }

    public function mount(): void
    {
        $this->rows = self::emptyRows(self::EMPTY_ROWS);
    }

    /**
     * The rows come back from the browser, which may send them in any shape: each one is
     * brought back to the four keys the component reads before any action runs.
     */
    #[PostHydrate]
    public function cleanRows(): void
    {
        $rows = [];
        /** @var array<mixed> $received */
        $received = $this->rows;

        foreach ($received as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $rows[] = [
                'key' => \is_string($row['key'] ?? null) && '' !== $row['key'] ? $row['key'] : self::newKey(),
                'reference' => \is_scalar($row['reference'] ?? null) ? (string) $row['reference'] : '',
                'quantity' => \is_scalar($row['quantity'] ?? null) ? (string) $row['quantity'] : '',
                'productSaleElementsId' => \is_scalar($row['productSaleElementsId'] ?? null) ? (string) $row['productSaleElementsId'] : null,
            ];
        }

        $this->rows = $rows;
    }

    #[LiveAction]
    public function addRow(): void
    {
        $this->rows = [...$this->rows, ...self::emptyRows(1)];
    }

    #[LiveAction]
    public function removeRow(#[LiveArg] string $key): void
    {
        $kept = [];
        $keptLines = [];

        foreach ($this->rows as $index => $row) {
            if ($row['key'] === $key) {
                continue;
            }

            $kept[] = $row;
            $keptLines[] = $this->lines[$index] ?? null;
        }

        $this->rows = [] === $kept ? self::emptyRows(1) : $kept;
        $this->lines = [] === $kept ? [] : $keptLines;
    }

    #[LiveAction]
    public function check(): void
    {
        $this->rejectedLines = [];
        $this->resolve();
    }

    #[LiveAction]
    public function import(): void
    {
        try {
            $parsed = $this->parser->parse($this->pastedText);
        } catch (ReferenceQuantityTextRefusedException) {
            $this->error = 'text_refused';

            return;
        }

        $this->pastedText = '';
        $this->rejectedLines = $parsed->rejected;

        if ([] === $parsed->rows) {
            return;
        }

        $imported = array_map(
            static fn (array $row): array => ['key' => self::newKey(), 'reference' => $row['reference'], 'quantity' => $row['quantity'], 'productSaleElementsId' => null],
            $parsed->rows,
        );

        $this->rows = [...array_values(array_filter($this->rows, static fn (array $row): bool => '' !== trim((string) $row['reference']))), ...$imported];
        $this->resolve();
    }

    #[LiveAction]
    public function addToCart(): void
    {
        $customer = $this->customer();
        $ready = [];

        foreach ($this->rows as $index => $row) {
            if ($this->isReady($index)) {
                $ready[] = new ReferenceQuantity((string) $row['reference'], (int) $row['quantity'], self::saleElementsIdOf($row));
            }
        }

        if ([] === $ready) {
            return;
        }

        try {
            $table = $this->quickOrderService->addToCart($customer, new ReferenceQuantityLines($ready));
        } catch (QuickOrderRateLimitedException) {
            $this->error = 'rate_limited';

            return;
        } catch (InvalidReferenceQuantityException) {
            $this->error = 'invalid_lines';

            return;
        }

        $this->addedCount = \count(array_filter($table->lines, static fn ($line): bool => $line->added));
        $rows = [];
        $lines = [];

        foreach ($this->rows as $index => $row) {
            if (!$this->isReady($index)) {
                $rows[] = $row;
                $lines[] = $this->lines[$index] ?? null;
            }
        }

        $this->rows = [] === $rows ? self::emptyRows(self::EMPTY_ROWS) : $rows;
        $this->lines = [] === $rows ? [] : $lines;
    }

    /**
     * The line of the last check for this row, or null when the row was edited since:
     * what the table showed for it no longer says anything about what it holds now.
     *
     * @return array<string, mixed>|null
     */
    public function lineOf(int $index): ?array
    {
        $row = $this->rows[$index] ?? null;
        $line = $this->lines[$index] ?? null;

        if (null === $row || null === $line) {
            return null;
        }

        if (mb_strtolower(trim((string) $row['reference'])) !== mb_strtolower((string) $line['reference'])
            || (int) $row['quantity'] !== (int) $line['quantity']) {
            return null;
        }

        return $line;
    }

    public function isReady(int $index): bool
    {
        return LineStatus::Resolved->value === ($this->lineOf($index)['status'] ?? null);
    }

    public function readyCount(): int
    {
        return \count(array_filter(array_keys($this->rows), $this->isReady(...)));
    }

    public function ambiguousCount(): int
    {
        return \count(array_filter(
            array_keys($this->rows),
            fn (int $index): bool => LineStatus::Ambiguous->value === ($this->lineOf($index)['status'] ?? null),
        ));
    }

    /**
     * A typed row whose quantity is not a whole number above zero: it is left out of the
     * check rather than let it refuse the whole table.
     */
    public function hasInvalidQuantity(int $index): bool
    {
        $row = $this->rows[$index] ?? null;

        return null !== $row && '' !== trim((string) $row['reference']) && null === self::quantityOf($row);
    }

    /**
     * Resolves every typed row and rebuilds the rows from the table: the core merges a
     * reference given twice into one line, so the rows follow the lines, not the reverse.
     * A reference shared by sale elements of one product opens on its default one, which
     * the buyer confirms by checking again.
     */
    private function resolve(): void
    {
        $customer = $this->customer();
        $typed = [];
        $leftOut = [];

        foreach ($this->rows as $row) {
            if ('' === trim((string) $row['reference'])) {
                continue;
            }

            $quantity = self::quantityOf($row);

            if (null === $quantity) {
                $leftOut[] = $row;

                continue;
            }

            $typed[] = new ReferenceQuantity((string) $row['reference'], $quantity, self::saleElementsIdOf($row));
        }

        if ([] === $typed) {
            $this->lines = array_fill(0, \count($this->rows), null);

            return;
        }

        try {
            $table = $this->quickOrderService->resolve($customer, new ReferenceQuantityLines($typed));
        } catch (QuickOrderRateLimitedException) {
            $this->error = 'rate_limited';

            return;
        } catch (InvalidReferenceQuantityException) {
            $this->error = 'invalid_lines';

            return;
        }

        $this->rows = [...self::rowsOf($table), ...$leftOut];
        $this->lines = [...$table->toArray()['lines'], ...array_fill(0, \count($leftOut), null)];
    }

    /**
     * @return list<array{key: string, reference: string, quantity: int, productSaleElementsId: int|null}>
     */
    private static function rowsOf(QuickOrderTable $table): array
    {
        $rows = [];

        foreach ($table->lines as $line) {
            $saleElementsId = $line->productSaleElementsId;

            foreach ($line->candidates as $candidate) {
                if ($candidate->preselected) {
                    $saleElementsId = $candidate->productSaleElementsId;
                }
            }

            $rows[] = ['key' => self::newKey(), 'reference' => $line->reference, 'quantity' => $line->quantity, 'productSaleElementsId' => $saleElementsId];
        }

        return $rows;
    }

    /**
     * @param array{quantity: int|string} $row
     */
    private static function quantityOf(array $row): ?int
    {
        $quantity = trim((string) $row['quantity']);

        return 1 === preg_match('/^[1-9]\d{0,8}$/', $quantity) ? (int) $quantity : null;
    }

    /**
     * @param array{productSaleElementsId?: int|string|null} $row
     */
    private static function saleElementsIdOf(array $row): ?int
    {
        $saleElementsId = (int) ($row['productSaleElementsId'] ?? 0);

        return $saleElementsId > 0 ? $saleElementsId : null;
    }

    /**
     * The page itself is behind the sign-in, but a component action is its own request:
     * it checks again rather than trust that it was rendered for a signed-in customer.
     */
    private function customer(): Customer
    {
        $customer = $this->securityContext->getCustomerUser();

        if (!$customer instanceof Customer) {
            throw new AccessDeniedHttpException('A customer must be signed in to order by reference.');
        }

        return $customer;
    }

    /**
     * @return list<array{key: string, reference: string, quantity: int, productSaleElementsId: null}>
     */
    private static function emptyRows(int $count): array
    {
        $rows = [];

        for ($n = 0; $n < $count; ++$n) {
            $rows[] = ['key' => self::newKey(), 'reference' => '', 'quantity' => 1, 'productSaleElementsId' => null];
        }

        return $rows;
    }

    private static function newKey(): string
    {
        return bin2hex(random_bytes(6));
    }
}
