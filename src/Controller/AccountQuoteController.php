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

namespace FlexyBundle\Controller;

use FlexyBundle\Service\CheckoutStepRouteResolver;
use FlexyBundle\Service\CollectionPaginator;
use Propel\Runtime\ActiveQuery\Criteria;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Api\Service\Quote\QuoteWriteLimiter;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\HttpKernel\Exception\RedirectException;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Quote\Cart\QuoteCartInspector;
use Thelia\Domain\Quote\Cart\QuoteCartSwitcher;
use Thelia\Domain\Quote\DTO\QuoteRequestInput;
use Thelia\Domain\Quote\Enum\QuoteMode;
use Thelia\Domain\Quote\Enum\QuoteState;
use Thelia\Domain\Quote\Exception\QuoteActionRefusedException;
use Thelia\Domain\Quote\Exception\QuoteDocumentUnavailableException;
use Thelia\Domain\Quote\Exception\QuoteException;
use Thelia\Domain\Quote\Exception\QuoteLinesUnavailableException;
use Thelia\Domain\Quote\Exception\QuoteRequestRefusedException;
use Thelia\Domain\Quote\Exception\QuoteTransitionRefusedException;
use Thelia\Domain\Quote\Pdf\QuotePdfService;
use Thelia\Domain\Quote\QuoteFacade;
use Thelia\Domain\Quote\Service\QuoteAccess;
use Thelia\Model\Cart;
use Thelia\Model\CartItemQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\Quote;
use Thelia\Model\QuoteQuery;

/**
 * The quotes of the account: the list, the request made from the cart, the page of a quote and its
 * printable document.
 *
 * A quote is read through the API of the storefront, which bounds it to what the signed-in account
 * reaches (its own and, when its role reads the orders of its company, its company's), and the page
 * asks the core judge a second time before it renders: what is out of reach answers the same 404 as a
 * number that does not exist, and a draft of the shop answers it too. The request is a POST with a
 * CSRF token, answered by a redirect.
 *
 * The buyer answers a quote the shop has sent: accepting it puts the cart of the quote in the session and
 * leads to the step of the checkout that still has something to do, refusing it closes it. Each is a POST
 * with the token of the account and the number of the revision answered, never an amount. Counter-proposing
 * has no route here: it is the live component of the page.
 */
#[Route('/account', name: 'account_')]
class AccountQuoteController extends FlexyController
{
    public const ACTION_TOKEN_ID = 'quote_action';

    public const QUOTES_PER_PAGE = 10;

    private const MAXIMUM_COMMENT_LENGTH = 5000;

    #[Route('/quotes', name: 'quotes', methods: ['GET'])]
    public function quotes(CollectionPaginator $paginator): Response
    {
        $this->checkAuth();

        // The scope is the API's, not a filter on the owner: read in-process, the collection is
        // bounded by the session customer and their company through the quote ownership rule, and
        // a filter on the owner would drop the quotes of the company the buyer is allowed to read.
        $quotes = $paginator->page('/api/front/account/quotes', [
            'order[id]' => 'desc',
        ], CollectionPaginator::requestedPage($this->getRequest()), self::QUOTES_PER_PAGE);

        return $this->render('account-quotes', [
            'quotes' => $quotes->members,
            'pagination' => $quotes->pagination(),
        ]);
    }

    /**
     * The form that turns the cart in hand into a request. The visitor who has not signed in is
     * sent to sign in and comes back here.
     */
    #[Route('/quote/request', name: 'quote_request', methods: ['GET'])]
    public function requestForm(CartFacade $cartFacade, QuoteCartInspector $inspector, QuoteAccess $access): Response
    {
        $this->assertTheShopTakesQuotes();
        $this->checkAuth();
        $cart = $this->assertTheCartMayBeQuoted($cartFacade, $inspector, $access);

        // A code of the cart, or a line it offers, is not carried onto the quote: said before the request is sent.
        $couponDropped = (float) $cart->getDiscount() > 0.0
            || CartItemQuery::create()->filterByCartId($cart->getId())->filterByOfferedByCouponId(null, Criteria::ISNOTNULL)->count() > 0;

        return $this->render('account-quote-request', ['couponDropped' => $couponDropped]);
    }

    #[Route('/quote/request', name: 'quote_create', methods: ['POST'])]
    public function create(
        CartFacade $cartFacade,
        QuoteCartInspector $inspector,
        QuoteAccess $access,
        QuoteFacade $quotes,
        QuoteWriteLimiter $limiter,
        CsrfTokenManagerInterface $csrfTokenManager,
        Request $request,
    ): Response {
        $this->assertTheShopTakesQuotes();
        $this->checkAuth();
        $this->checkToken($csrfTokenManager, $request);
        $cart = $this->assertTheCartMayBeQuoted($cartFacade, $inspector, $access);
        $customer = $this->signedInCustomer() ?? throw new NotFoundHttpException();

        $neededBy = $this->dayOf($request, 'needed_by');

        if (false === $neededBy) {
            $this->addFlash('error', $this->translator->trans('Please type a valid date for the day you need the goods.'));

            return $this->generateRedirect($this->generateUrl('account_quote_request'));
        }

        // After the token and the checks, before the work: a quote copies a whole cart.
        $limiter->spendRequest((int) $customer->getId());

        try {
            $quote = $quotes->requestFromCart($cart, $customer, new QuoteRequestInput(
                $this->commentOf($request),
                $neededBy,
                $this->idOf($request, 'invoice_address_id'),
                $this->idOf($request, 'delivery_address_id'),
            ));
        } catch (QuoteRequestRefusedException $refusal) {
            // The core states its refusals in English; the theme carries the catalogue, and a
            // sentence it does not have falls back to the sentence itself.
            $this->addFlash('error', $this->translator->trans($refusal->getMessage()));

            return $this->generateRedirect($this->generateUrl(
                \in_array($refusal->reason(), [QuoteRequestRefusedException::CART_EMPTY, QuoteRequestRefusedException::QUOTE_DISABLED], true) ? 'checkout_cart' : 'account_quote_request',
            ));
        }

        return $this->generateRedirect($this->generateUrl('account_quote', ['quoteId' => $quote->getId(), 'created' => 1]));
    }

    #[Route('/quote/{quoteId}', name: 'quote', requirements: ['quoteId' => '\d+'], methods: ['GET'])]
    public function quote(DataAccessService $dataAccessService, QuoteAccess $access, int $quoteId): Response
    {
        $this->readableQuote($access, $quoteId);

        // The template reads the quote through the API: if that comes back empty it would render
        // an empty shell in 200. Memoized, so this costs nothing extra.
        if (!\is_array($dataAccessService->resources('/api/front/account/quotes/'.$quoteId))) {
            throw new NotFoundHttpException();
        }

        return $this->render('account-quote', ['quoteId' => $quoteId]);
    }

    /**
     * Accepts the revision the quote stands at. What the buyer pays is what the quote says: the request names the
     * revision, and the cart the core builds from it takes the place of the cart of the session. The buyer is then
     * sent to the step of the checkout that still has something to do, which the progression of the checkout
     * reads off the cart: the delivery when the quote carries no address or no carrier the cart can use, the
     * payment when it does.
     */
    #[Route('/quote/{quoteId}/accept', name: 'quote_accept', requirements: ['quoteId' => '\d+'], methods: ['POST'])]
    public function accept(
        QuoteAccess $access,
        QuoteFacade $quotes,
        QuoteCartSwitcher $switcher,
        CheckoutStepRouteResolver $routes,
        CsrfTokenManagerInterface $csrfTokenManager,
        ClockInterface $clock,
        Request $request,
        int $quoteId,
    ): Response {
        $this->checkAuth();
        $this->checkToken($csrfTokenManager, $request);
        $quote = $this->readableQuote($access, $quoteId);
        $customer = $this->signedInCustomer() ?? throw new NotFoundHttpException();
        $revision = $this->revisionOf($request);

        try {
            $cart = $quotes->accept($quote, $customer, $revision);
        } catch (QuoteLinesUnavailableException $refusal) {
            $this->addFlash('error', $this->translator->trans($refusal->getMessage()));
            $this->addFlash('quote_unavailable_lines', $refusal->lines());

            return $this->backToTheQuote($quoteId);
        } catch (QuoteException $refusal) {
            return $this->refusedAnswer($refusal, $quote, $quotes, $clock, $quoteId, accepting: true);
        }

        $switcher->putInSession($cart, $this->getRequest());

        return $this->generateRedirect($routes->pathOfTheFirstIncompleteStep($cart));
    }

    /**
     * Declines the revision the quote stands at, once the buyer has confirmed: the confirmation is the button that
     * sends the form, and a request without it is refused.
     */
    #[Route('/quote/{quoteId}/reject', name: 'quote_reject', requirements: ['quoteId' => '\d+'], methods: ['POST'])]
    public function reject(
        QuoteAccess $access,
        QuoteFacade $quotes,
        CsrfTokenManagerInterface $csrfTokenManager,
        ClockInterface $clock,
        Request $request,
        int $quoteId,
    ): Response {
        $this->checkAuth();
        $this->checkToken($csrfTokenManager, $request);
        $quote = $this->readableQuote($access, $quoteId);
        $customer = $this->signedInCustomer() ?? throw new NotFoundHttpException();
        $revision = $this->revisionOf($request);

        if ('1' !== $request->request->get('confirm')) {
            throw new BadRequestHttpException();
        }

        try {
            $quotes->reject($quote, $customer, $revision);
        } catch (QuoteException $refusal) {
            return $this->refusedAnswer($refusal, $quote, $quotes, $clock, $quoteId);
        }

        return $this->generateRedirect($this->generateUrl('account_quote', ['quoteId' => $quoteId, 'rejected' => 1]));
    }

    /**
     * Gives the buyer their own cart back, in place of the cart of an accepted quote the session holds. The cart of the
     * quote is only taken out of the session: the quote stays as it was, and can be accepted again from its page, which
     * gives the same cart back. The way out is the one the core takes when a quote is paid: the cart that was set aside when
     * the buyer accepted comes back into the session, and when there was none the session gets an empty cart. Nothing is
     * done for a session that holds an ordinary cart.
     */
    #[Route('/quote/cart/leave', name: 'quote_cart_leave', methods: ['POST'])]
    public function leaveTheCartOfTheQuote(
        CartFacade $cartFacade,
        QuoteCartInspector $inspector,
        EventDispatcherInterface $dispatcher,
        CsrfTokenManagerInterface $csrfTokenManager,
        Request $request,
    ): Response {
        $this->checkAuth();
        $this->checkToken($csrfTokenManager, $request);

        $cart = $cartFacade->getCartFromSession();
        $session = $this->getSession();

        if (null !== $cart && $inspector->isQuoteCart($cart) && $session instanceof Session) {
            $session->clearSessionCart($dispatcher);
        }

        return $this->generateRedirect($this->generateUrl('checkout_cart'));
    }

    /**
     * The revisions of the quote, as the fragment the history of the page loads when it is opened:
     * the page itself carries the revision the quote stands at and nothing of what came before.
     */
    #[Route('/quote/{quoteId}/history', name: 'quote_history', requirements: ['quoteId' => '\d+'], methods: ['GET'])]
    public function history(DataAccessService $dataAccessService, QuoteAccess $access, int $quoteId): Response
    {
        $this->readableQuote($access, $quoteId);

        if (!\is_array($dataAccessService->resources('/api/front/account/quotes/'.$quoteId))) {
            throw new NotFoundHttpException();
        }

        return $this->render('account-quote-history', ['quoteId' => $quoteId]);
    }

    /**
     * One revision of the quote with its lines, as the fragment the history loads when the buyer opens it. A revision
     * the buyer does not reach, a draft and a number that does not exist all answer the same 404.
     */
    #[Route('/quote/{quoteId}/revision/{number}', name: 'quote_revision', requirements: ['quoteId' => '\d+', 'number' => '\d+'], methods: ['GET'])]
    public function revision(DataAccessService $dataAccessService, QuoteAccess $access, int $quoteId, int $number): Response
    {
        $this->readableQuote($access, $quoteId);

        if (!\is_array($dataAccessService->resources('/api/front/account/quotes/'.$quoteId.'/revisions/'.$number))) {
            throw new NotFoundHttpException();
        }

        return $this->render('account-quote-revision', ['quoteId' => $quoteId, 'number' => $number]);
    }

    /**
     * The printable document of a revision of the quote, the one the quote stands at when none is named.
     * Guarded like the invoice: a quote out of reach, a draft and a revision that was never sent all
     * answer the same 404.
     */
    #[Route('/quote/{quoteId}/pdf/{revision}', name: 'quote_pdf', requirements: ['quoteId' => '\d+', 'revision' => '\d+'], defaults: ['revision' => null], methods: ['GET'])]
    public function pdf(
        QuoteAccess $access,
        QuotePdfService $pdfService,
        #[Autowire(service: 'limiter.flexy_quote_pdf')]
        RateLimiterFactoryInterface $pdfLimiter,
        int $quoteId,
        ?int $revision = null,
    ): Response {
        $quote = $this->readableQuote($access, $quoteId);
        $customerId = (int) $this->signedInCustomer()?->getId();

        // The rendering is the costly part, and it is made again at each call: the budget of the
        // account is spent once the quote is known to be theirs.
        $limit = $pdfLimiter->create((string) $customerId)->consume();

        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }

        try {
            $pdf = $pdfService->render($quote, $revision ?? (int) $quote->getCurrentRevision());
        } catch (QuoteDocumentUnavailableException) {
            throw new NotFoundHttpException();
        }

        return $this->pdfResponse($pdf, preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $quote->getRef()) ?? 'quote');
    }

    /**
     * What the core refused an answer for, said on the page of the quote. A buyer whose role does not let them answer
     * was not offered the buttons: that request is not one a page sent, and it is refused as such.
     */
    private function refusedAnswer(QuoteException $refusal, Quote $quote, QuoteFacade $quotes, ClockInterface $clock, int $quoteId, bool $accepting = false): Response
    {
        if ($refusal instanceof QuoteActionRefusedException && QuoteActionRefusedException::NOT_PERMITTED === $refusal->reason()) {
            throw new AccessDeniedHttpException();
        }

        // A quote whose last day went by is told so, rather than that its state does not allow this.
        $message = $accepting && $refusal instanceof QuoteTransitionRefusedException && QuoteState::Expired === $quotes->effectiveState($quote, $clock->now()->setTime(0, 0))
            ? 'This quote has expired: it can no longer be accepted.'
            : $refusal->getMessage();

        $this->addFlash('error', $this->translator->trans($message));

        return $this->backToTheQuote($quoteId);
    }

    private function backToTheQuote(int $quoteId): Response
    {
        return $this->generateRedirect($this->generateUrl('account_quote', ['quoteId' => $quoteId]));
    }

    /**
     * The number of the revision the buyer answers. A request that does not name one is not one the page sent.
     */
    private function revisionOf(Request $request): int
    {
        return $this->idOf($request, 'revision') ?? throw new BadRequestHttpException();
    }

    /**
     * Quotes are only ever reached through the core judge: the quote the account placed or, with the
     * right to read the orders of their company, the ones of that company. Never look one up by
     * primary key alone, or a signed-in customer reads someone else's quote by walking the numbers.
     */
    private function readableQuote(QuoteAccess $access, int $quoteId): Quote
    {
        $this->checkAuth();

        $customer = $this->signedInCustomer();
        $quote = QuoteQuery::create()->findPk($quoteId);

        // The draft of the shop is not a quote the buyer knows of.
        if (null === $customer || null === $quote || QuoteState::Draft->value === $quote->getState() || !$access->canRead($customer, $quote)) {
            throw new NotFoundHttpException();
        }

        return $quote;
    }

    /**
     * The cart in hand, when it can be turned into a request: the shop takes requests, the cart is an
     * ordinary one with something in it, and the role of the buyer lets them ask. Anything else is a
     * page that does not exist, or the cart that has to be filled first.
     */
    private function assertTheCartMayBeQuoted(CartFacade $cartFacade, QuoteCartInspector $inspector, QuoteAccess $access): Cart
    {
        $customer = $this->signedInCustomer() ?? throw new NotFoundHttpException();

        if (!$access->canRequest($customer)) {
            throw new AccessDeniedHttpException();
        }

        $cart = $cartFacade->getCartFromSession();

        // The cart of an accepted quote is paid, not quoted again.
        if (null === $cart || $inspector->isQuoteCart($cart)) {
            throw new RedirectException($this->generateUrl('checkout_cart'));
        }

        return $cart;
    }

    /**
     * A shop that takes no quote has no request page at all: not a refusal, a 404.
     */
    private function assertTheShopTakesQuotes(): void
    {
        if (QuoteMode::Disabled === QuoteMode::fromConfig((string) ConfigQuery::read('quote_mode', QuoteMode::Disabled->value))) {
            throw new NotFoundHttpException();
        }
    }

    private function signedInCustomer(): ?Customer
    {
        $customer = $this->getSecurityContext()->getCustomerUser();

        return $customer instanceof Customer && !$customer->isGuest() ? $customer : null;
    }

    private function checkToken(CsrfTokenManagerInterface $csrfTokenManager, Request $request): void
    {
        if (!$csrfTokenManager->isTokenValid(new CsrfToken(self::ACTION_TOKEN_ID, (string) $request->request->get('_token')))) {
            throw new AccessDeniedHttpException();
        }
    }

    private function commentOf(Request $request): ?string
    {
        $comment = $request->request->all()['comment'] ?? '';
        $comment = \is_string($comment) ? trim($comment) : '';

        return '' === $comment ? null : mb_substr($comment, 0, self::MAXIMUM_COMMENT_LENGTH);
    }

    private function idOf(Request $request, string $field): ?int
    {
        $value = $request->request->all()[$field] ?? null;

        return \is_string($value) && ctype_digit($value) && '0' !== $value ? (int) $value : null;
    }

    /**
     * The day typed, null when none was, false when what was typed is not a day.
     */
    private function dayOf(Request $request, string $field): \DateTimeImmutable|false|null
    {
        $value = $request->request->all()[$field] ?? '';
        $value = \is_string($value) ? trim($value) : '';

        if ('' === $value) {
            return null;
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $day && $day->format('Y-m-d') === $value ? $day : false;
    }
}
