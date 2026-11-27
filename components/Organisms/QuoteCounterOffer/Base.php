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

namespace FlexyBundle\Components\Organisms\QuoteCounterOffer;

use FlexyBundle\Controller\AccountQuoteController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Api\Service\Quote\QuoteWriteLimiter;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Quote\DTO\QuoteItemRequestInput;
use Thelia\Domain\Quote\Enum\QuoteState;
use Thelia\Domain\Quote\Exception\QuoteException;
use Thelia\Domain\Quote\QuoteFacade;
use Thelia\Domain\Quote\Service\QuoteAccess;
use Thelia\Model\Customer;
use Thelia\Model\QuoteQuery;

/**
 * The table in which a buyer answers the revision a quote stands at with what they would rather have: for
 * each line, another quantity, another unit price without taxes, both or neither. A line left empty is not
 * discussed; no line is added and none is taken away.
 *
 * Typing calls nothing: every field is bound with `norender`, and the only request the table makes is the
 * submission, which carries what was typed and the token of the account. The revision named is the one the
 * table was drawn for, never one the browser chooses, and the core refuses an answer to a revision the quote
 * has moved past; the lines named are looked up in that revision by the core, which refuses any other.
 */
#[AsLiveComponent]
class Base
{
    use DefaultActionTrait;

    #[LiveProp]
    public int $quoteId = 0;

    #[LiveProp]
    public int $revisionNumber = 0;

    /**
     * What was typed, by the id of the line it is about.
     *
     * @var array<int|string, array{quantity?: mixed, price?: mixed}>
     */
    #[LiveProp(writable: true)]
    public array $requests = [];

    /** What the answer was refused for: the sentence, translated. Not a LiveProp: it answers this request only. */
    public ?string $error = null;

    /**
     * The lines whose typed value was not a figure, by line id: 'quantity' or 'price' or both.
     *
     * @var array<int, list<string>>
     */
    public array $fieldErrors = [];

    /** @var list<array<string, mixed>>|null */
    private ?array $linesOfTheRevision = null;

    public function __construct(
        private readonly DataAccessService $dataAccessService,
        private readonly QuoteFacade $quotes,
        private readonly QuoteAccess $access,
        private readonly QuoteWriteLimiter $limiter,
        private readonly SecurityContext $securityContext,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        // The `translator` service is the one carrying the theme catalogue, which the autowired interface is not.
        #[Autowire(service: 'translator')]
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<int|string> $lineIds the lines of the revision, so that every field bound to one has its place from the start
     */
    public function mount(int $quoteId, int $revisionNumber, array $lineIds = []): void
    {
        $this->quoteId = $quoteId;
        $this->revisionNumber = $revisionNumber;

        foreach ($lineIds as $lineId) {
            $this->requests[(int) $lineId] = ['quantity' => '', 'price' => ''];
        }
    }

    /**
     * The lines of the revision the table was drawn for, as the API hands them over. What the account does not
     * reach is not there: the table then has no line and draws nothing.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(): array
    {
        if (null !== $this->linesOfTheRevision) {
            return $this->linesOfTheRevision;
        }

        try {
            $revision = $this->dataAccessService->resources('/api/front/account/quotes/'.$this->quoteId.'/revisions/'.$this->revisionNumber);
        } catch (NotFoundHttpException) {
            $revision = null;
        }

        $lines = \is_array($revision) ? array_values(array_filter($revision['items'] ?? [], \is_array(...))) : [];

        return $this->linesOfTheRevision = $lines;
    }

    public function typed(int|string $lineId, string $field): string
    {
        $value = $this->requests[$lineId][$field] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }

    public function hasFieldError(int|string $lineId, string $field): bool
    {
        return \in_array($field, $this->fieldErrors[(int) $lineId] ?? [], true);
    }

    #[LiveAction]
    public function submit(#[LiveArg] string $token = ''): ?RedirectResponse
    {
        $customer = $this->securityContext->getCustomerUser();

        if (!$customer instanceof Customer || $customer->isGuest()) {
            throw new AccessDeniedHttpException();
        }

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(AccountQuoteController::ACTION_TOKEN_ID, $token))) {
            throw new AccessDeniedHttpException();
        }

        $quote = QuoteQuery::create()->findPk($this->quoteId);

        // What is out of reach, and the draft of the shop, are a quote that does not exist.
        if (null === $quote || QuoteState::Draft->value === $quote->getState() || !$this->access->canRead($customer, $quote)) {
            throw new NotFoundHttpException();
        }

        if (!$this->access->canAnswer($customer, $quote)) {
            throw new AccessDeniedHttpException();
        }

        $asked = $this->whatWasAsked();

        if ([] !== $this->fieldErrors) {
            $this->error = $this->translator->trans('Some of what you typed is not a figure. Correct the fields marked below.');

            return null;
        }

        if ([] === $asked) {
            $this->error = $this->translator->trans('A counter-proposal must ask for a quantity or a price on at least one line.');

            return null;
        }

        // After the checks, before the work: an answer wakes the shop up.
        try {
            $this->limiter->spendCounterOffer((int) $customer->getId());
        } catch (TooManyRequestsHttpException $refusal) {
            $this->error = $this->translator->trans($refusal->getMessage());

            return null;
        }

        try {
            $this->quotes->counterOffer($quote, $customer, $this->revisionNumber, $asked);
        } catch (QuoteException $refusal) {
            // The core states its refusals in English; the theme carries the catalogue.
            $this->error = $this->translator->trans($refusal->getMessage());

            return null;
        }

        return new RedirectResponse($this->urlGenerator->generate('account_quote', ['quoteId' => $this->quoteId, 'countered' => 1]));
    }

    /**
     * What the buyer typed, line by line: a quantity and a price are read as plain figures, a comma passes for the
     * decimal point, and an empty field asks nothing. The lines whose value is not a figure are kept in
     * `$fieldErrors`.
     *
     * @return list<QuoteItemRequestInput>
     */
    private function whatWasAsked(): array
    {
        $asked = [];
        $this->fieldErrors = [];

        foreach ($this->requests as $lineId => $typed) {
            if (!\is_array($typed) || !is_numeric($lineId)) {
                continue;
            }

            $quantity = $this->figureOf($typed['quantity'] ?? '', 6);
            $price = $this->figureOf($typed['price'] ?? '', 6);
            $errors = [];

            if (false === $quantity) {
                $errors[] = 'quantity';
            }

            if (false === $price) {
                $errors[] = 'price';
            }

            if ([] !== $errors) {
                $this->fieldErrors[(int) $lineId] = $errors;

                continue;
            }

            if (null !== $quantity || null !== $price) {
                $asked[] = new QuoteItemRequestInput((int) $lineId, null === $quantity ? null : (float) $quantity, $price);
            }
        }

        return $asked;
    }

    /**
     * The figure typed, as a plain decimal string; null when nothing was typed; false when it is not a figure above zero.
     */
    private function figureOf(mixed $typed, int $decimals): string|false|null
    {
        if (null === $typed) {
            return null;
        }

        if (!\is_scalar($typed)) {
            return false;
        }

        $text = str_replace(',', '.', trim((string) $typed));

        if ('' === $text) {
            return null;
        }

        if (1 !== preg_match('/^\d{1,12}(\.\d{1,'.$decimals.'})?$/', $text) || (float) $text <= 0.0) {
            return false;
        }

        return $text;
    }
}
