<?php

namespace FlexyBundle\Controller;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;

#[Route('/account', name: 'account_')]
class AccountOrderController extends FlexyController
{
    #[Route('/orders', name: 'orders')]
    public function orders(): Response
    {
        $this->checkAuth();

        return $this->render('account-orders');
    }

    #[Route('/order/{orderId}', name: 'order', requirements: ['orderId' => '\d+'])]
    public function order(?int $orderId = null): Response
    {
        $this->checkAuth();

        $order = $this->findCustomerOrder($orderId);

        // account-order.html.twig reads both order addresses unconditionally. An order
        // whose addresses no longer resolve cannot be displayed, so answer 404 rather
        // than let the template fail on a null id.
        if (null === $order->getOrderAddressRelatedByDeliveryOrderAddressId()
            || null === $order->getOrderAddressRelatedByInvoiceOrderAddressId()) {
            throw new NotFoundHttpException();
        }

        return $this->render('account-order', [
            'orderId' => $order->getId(),
        ]);
    }

    #[Route('/order/pdf/delivery/{orderId}', name: 'order_pdf_delivery', requirements: ['orderId' => '\d+'])]
    public function generateDeliveryPdf(EventDispatcherInterface $eventDispatcher, int $orderId): Response
    {
        $this->checkOrderCustomer($orderId);

        // Keep the core paid-order guard on: both flags must stay true for it to run.
        return $this->generateOrderPdf(
            $eventDispatcher,
            $orderId,
            ConfigQuery::read('pdf_delivery_file', 'delivery')
        );
    }

    #[Route('/order/pdf/invoice/{orderId}', name: 'order_pdf_invoice', requirements: ['orderId' => '\d+'])]
    public function generateInvoicePdf(EventDispatcherInterface $eventDispatcher, int $orderId): Response
    {
        $this->checkOrderCustomer($orderId);

        // Keep the core paid-order guard on: both flags must stay true for it to run.
        return $this->generateOrderPdf(
            $eventDispatcher,
            $orderId,
            ConfigQuery::read('pdf_invoice_file', 'invoice')
        );
    }


    #[Route('/order/pdf/quotation/{orderId}', name: 'order_pdf_quotation', requirements: ['orderId' => '\d+'])]
    public function generateQuotationPdf(EventDispatcherInterface $eventDispatcher, int $orderId): Response
    {
        $this->checkOrderCustomer($orderId);

        // A quotation is issued before payment, so the paid-order guard stays off here.
        return $this->generateOrderPdf(
            $eventDispatcher,
            $orderId,
            'quotation',
            false,
            false
        );
    }

    /**
     * Orders are only ever reachable through their owner: never look one up by primary
     * key alone, or any logged-in customer reads someone else's order by walking the
     * sequential ids. An unknown id and another customer's id answer the same 404, so
     * neither response confirms that the other order exists.
     */
    private function findCustomerOrder(?int $orderId): Order
    {
        $customerId = $this->getSecurityContext()->getCustomerUser()?->getId();

        $order = null === $orderId || null === $customerId
            ? null
            : OrderQuery::create()->filterByCustomerId($customerId)->findPk($orderId);

        if (null === $order) {
            throw new NotFoundHttpException();
        }

        return $order;
    }

    private function checkOrderCustomer(int $order_id): void
    {
        $this->checkAuth();

        $order = OrderQuery::create()->findPk($order_id);
        $valid = false;
        if ($order) {
            $customerOrder = $order->getCustomer();
            $customer = $this->getSecurityContext()->getCustomerUser();

            if ($customerOrder->getId() === $customer->getId()) {
                $valid = true;
            }
        }

        if (false === $valid) {
            throw new AccessDeniedHttpException();
        }
    }
}
