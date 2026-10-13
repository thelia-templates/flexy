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

use FlexyBundle\Exception\TooManyReturnRequestsException;
use FlexyBundle\Service\OrderReturnRequestService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Core\Event\Product\VirtualProductOrderDownloadResponseEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Template\Exception\ResourceNotFoundException;
use Thelia\Domain\OrderReturn\Exception\ReturnNotAllowedException;
use Thelia\Domain\OrderReturn\Service\OrderReturnPdfService;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Model\OrderProductQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderReturn as OrderReturnModel;
use Thelia\Model\OrderReturnQuery;
use Thelia\Model\OrderReturnStatus;

#[Route('/account', name: 'account_')]
class AccountOrderController extends FlexyController
{
    public const RETURN_REQUEST_TOKEN_ID = 'order_return_request';

    /**
     * The name of the return document in the active PDF template.
     */
    private const RETURN_DOCUMENT = 'order_return';

    /**
     * A return document is only worth printing once the merchant has agreed to take the
     * parcel back; before that there is nothing to slip into it.
     */
    private const DOCUMENT_STATUS_CODES = [
        OrderReturnStatus::CODE_ACCEPTED,
        OrderReturnStatus::CODE_RECEIVED,
        OrderReturnStatus::CODE_SETTLED,
    ];

    #[Route('/orders', name: 'orders')]
    public function orders(): Response
    {
        $this->checkAuth();

        return $this->render('account-orders');
    }

    #[Route('/order/{orderId}', name: 'order', requirements: ['orderId' => '\d+'])]
    public function order(DataAccessService $dataAccessService, int $orderId): Response
    {
        $order = $this->findCustomerOrder($orderId);

        // The template reads the order through the API: if that comes back empty it would
        // render an empty shell in 200. Memoized, so this costs nothing extra.
        if (null === $dataAccessService->resources('/api/front/account/orders/'.$orderId)) {
            throw new NotFoundHttpException();
        }

        return $this->render('account-order', ['orderId' => $orderId]);
    }

    #[Route('/order/pdf/delivery/{orderId}', name: 'order_pdf_delivery', requirements: ['orderId' => '\d+'])]
    public function generateDeliveryPdf(EventDispatcherInterface $eventDispatcher, int $orderId): Response
    {
        $this->findCustomerOrder($orderId);

        return $this->generateOrderPdf(
            $eventDispatcher,
            $orderId,
            ConfigQuery::read('pdf_delivery_file', 'delivery'),
            checkOrderStatus: true,
            checkAdminUser: true,
        );
    }

    /**
     * The delivery note of one parcel of the order. A parcel that is not the order's answers
     * the same 404 as an order that is not the customer's: nothing says which one is wrong.
     */
    #[Route('/order/pdf/delivery/{orderId}/{shipmentId}', name: 'order_pdf_delivery_shipment', requirements: ['orderId' => '\d+', 'shipmentId' => '\d+'])]
    public function generateShipmentDeliveryPdf(EventDispatcherInterface $eventDispatcher, int $orderId, int $shipmentId): Response
    {
        $this->findCustomerOrder($orderId);

        return $this->generateOrderPdf(
            $eventDispatcher,
            $orderId,
            ConfigQuery::read('pdf_delivery_file', 'delivery'),
            checkOrderStatus: true,
            checkAdminUser: true,
            shipmentId: $shipmentId,
        );
    }

    #[Route('/order/pdf/invoice/{orderId}', name: 'order_pdf_invoice', requirements: ['orderId' => '\d+'])]
    public function generateInvoicePdf(EventDispatcherInterface $eventDispatcher, int $orderId): Response
    {
        $this->findCustomerOrder($orderId);

        return $this->generateOrderPdf(
            $eventDispatcher,
            $orderId,
            ConfigQuery::read('pdf_invoice_file', 'invoice'),
            checkOrderStatus: true,
            checkAdminUser: true,
        );
    }

    #[Route('/order/pdf/quotation/{orderId}', name: 'order_pdf_quotation', requirements: ['orderId' => '\d+'])]
    public function generateQuotationPdf(EventDispatcherInterface $eventDispatcher, int $orderId): Response
    {
        $this->findCustomerOrder($orderId);

        // A quote is drawn from a `quotation` document of the active PDF template, and the
        // default template ships an invoice and a delivery slip only. A shop that has not
        // added one has nothing to render here, which is a 404 and not a server error.
        if (!$this->pdfDocumentExists('quotation')) {
            throw new NotFoundHttpException();
        }

        return $this->generateOrderPdf(
            $eventDispatcher,
            $orderId,
            // A quotation is by definition not paid: the status guard must stay off here.
            'quotation',
            checkOrderStatus: false,
            checkAdminUser: false,
        );
    }

    /**
     * The form opening a return on an order. Everything it offers — which lines, and how
     * many of each — is read from the eligibility service, and read again on submit.
     */
    #[Route('/order/{orderId}/return', name: 'order_return_new', requirements: ['orderId' => '\d+'], methods: ['GET'])]
    public function orderReturnNew(
        ReturnEligibilityChecker $eligibility,
        OrderReturnRequestService $returnRequestService,
        int $orderId,
    ): Response {
        $this->assertReturnsEnabled($eligibility);
        $order = $this->findCustomerOrder($orderId);

        // Nothing left to return, or the window has closed: the page that would open a
        // return no longer exists, exactly as the button offering it no longer shows.
        if (!$eligibility->isReturnable($order)) {
            throw new NotFoundHttpException();
        }

        return $this->render('account-order-return', [
            'orderId' => $orderId,
            'returnableQuantities' => $returnRequestService->returnableQuantities($order),
        ]);
    }

    #[Route('/order/{orderId}/return', name: 'order_return_create', requirements: ['orderId' => '\d+'], methods: ['POST'])]
    public function orderReturnCreate(
        ReturnEligibilityChecker $eligibility,
        OrderReturnRequestService $returnRequestService,
        CsrfTokenManagerInterface $csrfTokenManager,
        Request $request,
        int $orderId,
    ): Response {
        $this->assertReturnsEnabled($eligibility);
        $order = $this->findCustomerOrder($orderId);
        $this->checkReturnRequestToken($csrfTokenManager, $request);

        $customer = $this->getSecurityContext()->getCustomerUser();

        if (!$customer instanceof Customer) {
            throw new NotFoundHttpException();
        }

        try {
            $return = $returnRequestService->open(
                $order,
                $customer,
                $this->readRequestedLines($request),
                $this->readOptionalId($request, 'reason'),
                (string) $request->request->get('resolution', ''),
                $this->readComment($request),
            );
        } catch (ReturnNotAllowedException|TooManyReturnRequestsException $exception) {
            // The core states its refusals in English; the theme carries the catalogue,
            // and an entry it does not have falls back to the sentence itself.
            $this->addFlash('error', $this->translator->trans($exception->getMessage()));

            return $this->generateRedirect($this->generateUrl('account_order_return_new', ['orderId' => $orderId]));
        }

        return $this->generateRedirect($this->generateUrl('account_return', [
            'returnId' => $return->getId(),
            'created' => 1,
        ]));
    }

    /**
     * The tracking page of one return, read-only.
     */
    #[Route('/return/{returnId}', name: 'return', requirements: ['returnId' => '\d+'], methods: ['GET'])]
    public function orderReturn(ReturnEligibilityChecker $eligibility, int $returnId): Response
    {
        $this->assertReturnsEnabled($eligibility);
        $return = $this->findCustomerReturn($returnId);

        return $this->render('account-return', [
            'returnId' => $returnId,
            'orderId' => (int) $return->getOrderId(),
            'lines' => $this->returnLines($return),
            'documentAvailable' => $this->returnDocumentAvailable($return),
        ]);
    }

    /**
     * The printable return document. Guarded like the invoice: the return is reached
     * through its owner, and an id belonging to someone else answers the same 404 as an
     * id that does not exist.
     */
    #[Route('/return/pdf/{returnId}', name: 'return_pdf', requirements: ['returnId' => '\d+'], methods: ['GET'])]
    public function generateReturnPdf(
        ReturnEligibilityChecker $eligibility,
        OrderReturnPdfService $pdfService,
        int $returnId,
    ): Response {
        $this->assertReturnsEnabled($eligibility);
        $return = $this->findCustomerReturn($returnId);

        if (!$this->returnDocumentAvailable($return)) {
            throw new NotFoundHttpException();
        }

        $pdf = $pdfService->render($return);

        if (null === $pdf) {
            throw new NotFoundHttpException();
        }

        return $this->pdfResponse($pdf, (string) $return->getRef());
    }

    /**
     * Serves the file bought with a virtual product. The theme never reads that file: it
     * says who is asking and for which order line, and the module that stores the file
     * answers with it. Anything else would tie the theme to one way of storing documents.
     */
    #[Route('/order/download/{orderProductId}', name: 'order_download', requirements: ['orderProductId' => '\d+'], methods: ['GET'])]
    public function downloadVirtualProduct(EventDispatcherInterface $eventDispatcher, int $orderProductId): Response
    {
        $this->checkAuth();

        $customerId = $this->getSecurityContext()->getCustomerUser()?->getId();

        $orderProduct = null === $customerId
            ? null
            : OrderProductQuery::create()
                ->useOrderQuery()
                    ->filterByCustomerId($customerId)
                ->endUse()
                ->findPk($orderProductId);

        // A line of someone else's order, an unknown id, an order that is not paid for and
        // a line no document was ever attached to all answer the same 404: none of them
        // tells whether the file is there to be had. `virtual` alone is not enough — it
        // says the product was sold as a file, not that one was attached to the line.
        if (null === $orderProduct
            || null === $orderProduct->getVirtualDocument()
            || !$orderProduct->getOrder()->isPaid(false)
        ) {
            throw new NotFoundHttpException();
        }

        $event = new VirtualProductOrderDownloadResponseEvent($orderProduct);
        $eventDispatcher->dispatch($event, TheliaEvents::VIRTUAL_PRODUCT_ORDER_DOWNLOAD_RESPONSE);

        $response = $event->getResponse();

        // No module answered, so there is no file to serve. A shop without a virtual
        // product module is a 404 here, not a 500.
        if (!$response instanceof Response) {
            throw new NotFoundHttpException();
        }

        return $response;
    }

    /**
     * Whether the active PDF template can render this document. The parser resolver answers
     * for every engine the shop has, so a `quotation.html.twig` and a Smarty `quotation.html`
     * both count, and neither extension is spelled out here.
     */
    private function pdfDocumentExists(string $document): bool
    {
        try {
            $this->parserResolver->getParser(
                $this->templateHelper->getActivePdfTemplate()->getAbsolutePath(),
                $document,
            );
        } catch (ResourceNotFoundException) {
            return false;
        }

        return true;
    }

    /**
     * A shop with the returns feature off has no return pages at all: not a refusal, a 404,
     * the same answer the core gives on the API resources of the feature. The core listener
     * guards the API routes only — a theme controller never passes through it.
     */
    private function assertReturnsEnabled(ReturnEligibilityChecker $eligibility): void
    {
        if (!$eligibility->isFeatureEnabled()) {
            throw new NotFoundHttpException();
        }
    }

    /**
     * Opening a return changes state, so the form is POST-only and carries a CSRF token:
     * the session cookie is SameSite=Lax, which a top-level navigation would still send.
     * The token is session-bound rather than stateless — account pages are never served
     * from a shared cache, so there is no snapshot to replay.
     */
    private function checkReturnRequestToken(CsrfTokenManagerInterface $csrfTokenManager, Request $request): void
    {
        $token = new CsrfToken(self::RETURN_REQUEST_TOKEN_ID, (string) $request->request->get('_token'));

        if (!$csrfTokenManager->isTokenValid($token)) {
            throw new AccessDeniedHttpException();
        }
    }

    /**
     * The lines the customer ticked, as quantity by order product id. Nothing here is
     * trusted: an id that is not a line of the order, and a quantity above what is still
     * returnable, are both refused further down by the eligibility service — which reads
     * the order from the database, not from this payload.
     *
     * @return array<int, float>
     */
    private function readRequestedLines(Request $request): array
    {
        $lines = $request->request->all('lines');
        $requested = [];

        foreach ($lines as $orderProductId => $line) {
            if (!\is_array($line) || !isset($line['selected'])) {
                continue;
            }

            $quantity = (float) ($line['quantity'] ?? 0);

            if ($quantity > 0) {
                $requested[(int) $orderProductId] = $quantity;
            }
        }

        return $requested;
    }

    private function readOptionalId(Request $request, string $field): ?int
    {
        $value = $request->request->get($field);

        return null === $value || '' === $value ? null : (int) $value;
    }

    private function readComment(Request $request): ?string
    {
        $comment = trim((string) $request->request->get('comment', ''));

        return '' === $comment ? null : $comment;
    }

    /**
     * Returns are only ever reached through their owner, on the model of findCustomerOrder():
     * an unknown id and someone else's return answer the same 404, so neither response
     * confirms that the other return exists.
     */
    private function findCustomerReturn(int $returnId): OrderReturnModel
    {
        $this->checkAuth();

        $customerId = $this->getSecurityContext()->getCustomerUser()?->getId();
        $return = null === $customerId
            ? null
            : OrderReturnQuery::create()->filterByCustomerId($customerId)->findPk($returnId);

        if (null === $return) {
            throw new NotFoundHttpException();
        }

        return $return;
    }

    /**
     * The returned lines, as the page shows them.
     *
     * Read off the models rather than through the API: the core exposes the order product
     * of a return line as a relation, but none of that product's own properties carry the
     * return's front read group, so the nested object normalizes to nothing. Same shape as
     * the context the core hands the PDF template, so both read alike.
     *
     * @return list<array{title: string, ref: string, quantity: float, refund: float}>
     */
    private function returnLines(OrderReturnModel $return): array
    {
        $lines = [];

        foreach ($return->getOrderReturnLines() as $line) {
            $orderProduct = $line->getOrderProduct();

            $lines[] = [
                'title' => (string) $orderProduct?->getTitle(),
                'ref' => (string) $orderProduct?->getProductRef(),
                'quantity' => (float) $line->getQuantity(),
                'refund' => (float) $line->getRefundAmount(),
            ];
        }

        return $lines;
    }

    /**
     * Whether the printable document exists for this return: the merchant has accepted it,
     * and the active PDF template ships the document. The PDF template is its own package
     * on its own release cycle, so a shop running an older one gets a 404, not a 500.
     */
    private function returnDocumentAvailable(OrderReturnModel $return): bool
    {
        return \in_array($return->getStatusCode(), self::DOCUMENT_STATUS_CODES, true)
            && $this->pdfDocumentExists(self::RETURN_DOCUMENT);
    }

    /**
     * Orders are only ever reached through their owner: never look one up by primary key
     * alone, or a logged-in customer reads someone else's order by walking the sequential
     * ids. An unknown id and another customer's id answer the same 404, so neither response
     * confirms that the other order exists.
     */
    private function findCustomerOrder(int $orderId): Order
    {
        $this->checkAuth();

        $customerId = $this->getSecurityContext()->getCustomerUser()?->getId();
        $order = null === $customerId
            ? null
            : OrderQuery::create()->filterByCustomerId($customerId)->findPk($orderId);

        if (null === $order) {
            throw new NotFoundHttpException();
        }

        return $order;
    }
}
