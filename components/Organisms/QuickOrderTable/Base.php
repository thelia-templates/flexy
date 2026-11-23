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

use FlexyBundle\DTO\QuantityRuleDTO;
use FlexyBundle\Event\CheckoutEvents;
use FlexyBundle\Exception\ReferenceQuantityTextRefusedException;
use FlexyBundle\Service\PurchaseListChoices;
use FlexyBundle\Service\QuickOrderService;
use FlexyBundle\Service\ReferenceQuantityTextParser;
use FlexyBundle\Service\SaleElementQuantityRules;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Cart\Exception\InvalidCartException;
use Thelia\Domain\Catalog\DTO\ReferenceQuantity;
use Thelia\Domain\Catalog\DTO\ReferenceQuantityLines;
use Thelia\Domain\Catalog\Exception\InvalidReferenceQuantityException;
use Thelia\Domain\Catalog\Quantity\QuantityScale;
use Thelia\Domain\CustomerList\Exception\InvalidPurchaseListException;
use Thelia\Domain\CustomerList\Exception\PurchaseListAccessDeniedException;
use Thelia\Domain\CustomerList\Exception\PurchaseListNotFoundException;
use Thelia\Domain\CustomerList\PurchaseListFacade;
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
 *
 * Given a purchase list, the table opens on its lines, checked, and can save them back:
 * a reference that no longer resolves stays on the list and shows as unknown, which is
 * how a buyer learns that an old list has references the shop dropped. Opened on no
 * list, the table saves into a new list or adds to one the customer may change.
 */
#[AsLiveComponent]
class Base
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    public const int EMPTY_ROWS = 1;

    /** The status of a line the destination restrictions of the core keep from the address of the cart. */
    private const string DESTINATION_REFUSED = 'destination_refused';

    /**
     * What the buyer typed. The sale element is the one a reference shared by several
     * of them was settled on; the core checks that it still carries the reference.
     *
     * @var list<array{key: string, reference: string, quantity: int|float|string, productSaleElementsId: int|string|null}>
     */
    #[LiveProp(writable: true)]
    public array $rows = [];

    /**
     * The control table of the last check, one entry per row, in the same order. A row
     * left out of the check (its quantity is not a number above zero the core takes) has none.
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

    /** The purchase list the table was opened on, if any. */
    #[LiveProp]
    public ?int $listId = null;

    #[LiveProp]
    public bool $listWritable = false;

    /** Where "save as a purchase list" goes: empty for a new list, else a list id. */
    #[LiveProp(writable: true)]
    public string $saveTarget = PurchaseListChoices::NEW_LIST;

    #[LiveProp(writable: true)]
    public string $saveTitle = '';

    public ?string $error = null;

    /**
     * The reason a module gave when the cart refused the lines ('cart_refused'), printed escaped.
     */
    public string $cartRefusalReason = '';

    public int $addedCount = 0;

    public bool $saved = false;

    /**
     * The quantity rule of each sale element the last check answered, read once per request.
     *
     * @var array<int, array{minQuantity: float, quantityStep: float, quantityDecimals: int, unitQuantity: float|null, referenceUnitQuantity: float|null, unitCode: string|null}>|null
     */
    private ?array $rules = null;

    public function __construct(
        private readonly QuickOrderService $quickOrderService,
        private readonly ReferenceQuantityTextParser $parser,
        private readonly SecurityContext $securityContext,
        private readonly PurchaseListFacade $purchaseListFacade,
        private readonly PurchaseListChoices $purchaseListChoices,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly SaleElementQuantityRules $saleElementQuantityRules,
    ) {
    }

    public function mount(?int $listId = null): void
    {
        $this->rows = self::emptyRows(self::EMPTY_ROWS);

        if (null === $listId) {
            return;
        }

        $customer = $this->customer();

        try {
            $list = $this->purchaseListFacade->getVisible($customer, $listId);
        } catch (PurchaseListNotFoundException $exception) {
            throw new NotFoundHttpException('No such purchase list.', $exception);
        }

        $this->listId = $listId;
        $this->listWritable = $this->purchaseListFacade->canWrite($customer, $list);

        try {
            $table = $this->quickOrderService->resolvePurchaseList($customer, $listId);
        } catch (QuickOrderRateLimitedException) {
            // The lines still show, unchecked: the buyer can check them in a minute.
            $this->error = 'rate_limited';
            $unchecked = array_map(
                static fn (ReferenceQuantity $line): array => ['key' => self::newKey(), 'reference' => $line->reference, 'quantity' => $line->quantity, 'productSaleElementsId' => $line->productSaleElementsId],
                $this->purchaseListFacade->linesToLoad($customer, $listId),
            );
            $this->rows = [] === $unchecked ? $this->rows : $unchecked;

            return;
        }

        if ([] !== $table->lines) {
            $this->rows = self::rowsOf($table);
            $this->lines = $table->toArray()['lines'];
        }
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
        $readyIndexes = [];

        foreach ($this->rows as $index => $row) {
            if ($this->isReady($index)) {
                $ready[] = new ReferenceQuantity((string) $row['reference'], (float) self::quantityOf($row), self::saleElementsIdOf($row));
                $readyIndexes[] = $index;
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
        } catch (InvalidCartException $exception) {
            // A module's CART_ADDITEM listener refused a line: the core rolls the whole table
            // back, so nothing reached the cart and every row stays for the buyer to correct.
            $this->error = 'cart_refused';
            $this->cartRefusalReason = trim($exception->getMessage());

            return;
        }

        if ($this->keepRowsADestinationRefuses($table, $readyIndexes)) {
            return;
        }

        $this->addedCount = \count(array_filter($table->lines, static fn ($line): bool => $line->added));

        $this->tellThePageWhatWasAdded($table);

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
     * A line the destination restrictions keep from the address of the cart: the core added none of the
     * table, so every row stays, each with the line the core answered, for the buyer to remove the refused
     * one and add the others again. The status is compared as a string: a core without the restrictions
     * never answers it.
     *
     * @param list<int> $readyIndexes the rows sent, in the order they were sent
     */
    private function keepRowsADestinationRefuses(QuickOrderTable $table, array $readyIndexes): bool
    {
        $answered = $table->toArray()['lines'];

        if ([] === array_filter($answered, static fn (array $line): bool => self::DESTINATION_REFUSED === $line['status'])) {
            return false;
        }

        $byReference = [];

        foreach ($answered as $line) {
            $byReference[self::referenceKey((string) $line['reference'])] = $line;
        }

        foreach ($readyIndexes as $index) {
            $line = $byReference[self::referenceKey((string) $this->rows[$index]['reference'])] ?? null;

            if (null !== $line) {
                $this->lines[$index] = $line;
            }
        }

        $this->error = 'destination_refused';

        return true;
    }

    /**
     * Tells the page, through a DOM event per line (CheckoutEvents::BROWSER_ADD_PSE), what the cart took. A line the cart took
     * whole is told; one it took only in part (the stock) or turned down is not: the table does not say how much of it went in.
     */
    protected function tellThePageWhatWasAdded(QuickOrderTable $table): void
    {
        foreach ($table->lines as $line) {
            if ($line->added) {
                $this->dispatchBrowserEvent(CheckoutEvents::BROWSER_ADD_PSE, ['pse' => (int) $line->productSaleElementsId, 'quantity' => $line->quantity]);
            }
        }
    }

    /**
     * Replaces the lines of the list with the typed rows that a list can keep.
     */
    #[LiveAction]
    public function saveList(): void
    {
        $customer = $this->customer();

        if (null === $this->listId) {
            return;
        }

        try {
            $this->purchaseListFacade->replaceItems($customer, $this->listId, new ReferenceQuantityLines($this->savableLines()));
        } catch (PurchaseListNotFoundException $exception) {
            throw new NotFoundHttpException('No such purchase list.', $exception);
        } catch (PurchaseListAccessDeniedException $exception) {
            throw new AccessDeniedHttpException('This purchase list cannot be changed.', $exception);
        } catch (InvalidReferenceQuantityException|InvalidPurchaseListException) {
            $this->error = 'invalid_lines';

            return;
        }

        $this->saved = true;
    }

    /**
     * Saves the typed rows into a new list, or adds them to a list the customer may
     * change, then opens that list. The rows are the ones "save the list" would keep.
     */
    #[LiveAction]
    public function saveAsList(): ?RedirectResponse
    {
        $customer = $this->customer();
        $lines = $this->savableLines();

        if ([] === $lines) {
            $this->error = 'nothing_to_save';

            return null;
        }

        try {
            $lines = new ReferenceQuantityLines($lines);
            $list = PurchaseListChoices::NEW_LIST === $this->saveTarget
                ? $this->purchaseListFacade->create($customer, $this->saveTitle, $lines)
                : $this->purchaseListFacade->appendItems($customer, (int) $this->saveTarget, $lines);
        } catch (PurchaseListNotFoundException $exception) {
            throw new NotFoundHttpException('No such purchase list.', $exception);
        } catch (PurchaseListAccessDeniedException $exception) {
            throw new AccessDeniedHttpException('This purchase list cannot be changed.', $exception);
        } catch (InvalidPurchaseListException) {
            $this->error = 'list_refused';

            return null;
        } catch (InvalidReferenceQuantityException) {
            $this->error = 'invalid_lines';

            return null;
        }

        return new RedirectResponse($this->urlGenerator->generate('account_purchase_list', [
            'listId' => $list->getId(),
            PurchaseListChoices::NEW_LIST === $this->saveTarget ? 'created' : 'appended' => 1,
        ]));
    }

    /**
     * The lists the rows can be added to; none when rendered without a customer, as in
     * the toolkit, where the save action answers 403 anyway.
     *
     * @return list<array{value: string, label: string}>
     */
    public function writableLists(): array
    {
        $customer = $this->securityContext->getCustomerUser();

        return $customer instanceof Customer ? $this->purchaseListChoices->writableListsOf($customer) : [];
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

        $quantity = self::quantityOf($row);

        if (self::referenceKey((string) $row['reference']) !== self::referenceKey((string) $line['reference'])
            || null === $quantity
            || !QuantityScale::isTheSameQuantity($quantity, (float) $line['quantity'])) {
            return null;
        }

        return $line;
    }

    /**
     * The quantity rule of the sale element the last check answered for this row, or null
     * when the row was edited since, names no sale element, or the core has no rules.
     *
     * @return array{minQuantity: float, quantityStep: float, quantityDecimals: int, unitQuantity: float|null, referenceUnitQuantity: float|null, unitCode: string|null}|null
     */
    public function ruleOf(int $index): ?array
    {
        $saleElementsId = (int) ($this->lineOf($index)['productSaleElementsId'] ?? 0);

        return $saleElementsId > 0 ? ($this->rules()[$saleElementsId] ?? null) : null;
    }

    /**
     * The rule the quantity selector of this row follows, the one of the product page: whole steps
     * for a sale element sold by the piece, decimals and a unit for one sold by measure. A row the
     * check has not settled on a sale element yet takes any quantity of up to three decimals, the
     * check then telling the rule of its sale element.
     */
    public function quantityRuleOf(int $index): QuantityRuleDTO
    {
        $rule = $this->ruleOf($index);

        return null !== $rule
            ? QuantityRuleDTO::fromArray($rule)
            : new QuantityRuleDTO(minQuantity: 1.0, step: 1.0, decimals: QuantityScale::MAXIMUM_DECIMALS);
    }

    /**
     * The quantity in the field of this row: what the buyer typed, as typed, or the quantity the
     * check answered, with the decimals of its rule in the language of the page.
     */
    public function quantityFieldOf(int $index, ?string $locale = null): string
    {
        $quantity = $this->rows[$index]['quantity'] ?? '';

        if (\is_string($quantity)) {
            return $quantity;
        }

        if (null === $this->ruleOf($index)) {
            return self::writtenPlain((float) $quantity);
        }

        return $this->quantityRuleOf($index)->fieldValue((float) $quantity, $locale);
    }

    /**
     * @return array<int, array{minQuantity: float, quantityStep: float, quantityDecimals: int, unitQuantity: float|null, referenceUnitQuantity: float|null, unitCode: string|null}>
     */
    private function rules(): array
    {
        if (null !== $this->rules) {
            return $this->rules;
        }

        $ids = array_values(array_unique(array_filter(array_map(
            static fn (?array $line): int => (int) ($line['productSaleElementsId'] ?? 0),
            $this->lines,
        ))));

        return $this->rules = $this->saleElementQuantityRules->ofSaleElements($ids);
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
     * A typed row whose quantity is not a number above zero, up to the core's maximum and with three decimals
     * at most: it is left out of the check rather than let it refuse the whole table. Whether the sale element
     * takes it (its minimum, step and decimals) is the check's answer.
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
     * @return list<array{key: string, reference: string, quantity: float, productSaleElementsId: int|null}>
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
     * The sale element the row names, if the last check offered it for this row: the
     * one it resolved to, or one of the candidates of an ambiguous reference.
     */
    private function offeredSaleElementsIdOf(int $index): ?int
    {
        $saleElementsId = self::saleElementsIdOf($this->rows[$index] ?? []);
        $line = $this->lineOf($index);

        if (null === $saleElementsId || null === $line) {
            return null;
        }

        $offered = [(int) ($line['productSaleElementsId'] ?? 0)];

        foreach ((array) ($line['candidates'] ?? []) as $candidate) {
            $offered[] = (int) ($candidate['productSaleElementsId'] ?? 0);
        }

        return \in_array($saleElementsId, $offered, true) ? $saleElementsId : null;
    }

    /**
     * The typed rows a list can keep: a row whose quantity is not a number above zero is
     * left out. The sale element a row names is kept only when the last check
     * offered it for that row: the row comes from the browser, and the list stores
     * whatever sale element it is given.
     *
     * @return list<ReferenceQuantity>
     */
    private function savableLines(): array
    {
        $lines = [];

        foreach ($this->rows as $index => $row) {
            $quantity = self::quantityOf($row);

            if ('' === trim((string) $row['reference']) || null === $quantity) {
                continue;
            }

            $lines[] = new ReferenceQuantity((string) $row['reference'], $quantity, $this->offeredSaleElementsIdOf($index));
        }

        return $lines;
    }

    /**
     * The quantity a row holds, "1,25" as well as "1.25", or null when it is not a number
     * above zero, up to the core's maximum, with three decimals at most: read as a pasted
     * line is, spaces aside.
     *
     * @param array{quantity: int|float|string} $row
     */
    private static function quantityOf(array $row): ?float
    {
        $typed = \is_string($row['quantity']) ? $row['quantity'] : self::writtenPlain((float) $row['quantity']);

        return ReferenceQuantityTextParser::quantityOf(str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($typed)));
    }

    /**
     * A reference as the core matches it: invisible characters and spaces normalised, case ignored.
     */
    private static function referenceKey(string $reference): string
    {
        return mb_strtolower(ReferenceQuantityLines::normalizeReference($reference));
    }

    /**
     * A quantity with its decimals and no trailing zeros, "1.25", "3": the way a quantity the
     * check answered is read back, before any sale element rule writes it.
     */
    private static function writtenPlain(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, QuantityScale::MAXIMUM_DECIMALS, '.', ''), '0'), '.');
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
