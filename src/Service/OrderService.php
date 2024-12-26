<?php

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

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Map\TableMap;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Base\OrderProductQuery;
use Thelia\Model\Map\OrderProductTableMap;
use Thelia\Model\Map\OrderProductTaxTableMap;
use Thelia\Model\OrderQuery;
use TwigEngine\Service\DataAccess\DataAccessService;
use Propel\Runtime\ActiveQuery\Join;
use RuntimeException;
use Thelia\Model\Base\ProductSaleElementsQuery;
use Thelia\Model\Customer;

class OrderService
{
  protected $session;

  public function __construct(private readonly RequestStack $requestStack, private DataAccessService $dataAccessService, private ProductSaleElementsService $pseService)
  {
    $request = $this->requestStack->getCurrentRequest();

    /** @var Session $session */
    $this->session = $request->getSession();
  }

  public function getCustomerOrders(): array|ObjectCollection
  {
    $orderList = [];
    $customer = $this->session->getCustomerUser();

    if (!$customer instanceof Customer) {
      throw new RuntimeException('Customer not found');
    }

    $ordersQuery = OrderQuery::create();
    $ordersQuery->findByCustomerId($this->session->getCustomerUser()->getId());

    $orders = $ordersQuery->find();

    foreach ($orders as $order) {
      $orderList[] = $this->getExtendOrderProductInfo($order);
    }

    return $orderList;
  }

  public function getOrder(int $id = null)
  {
    if (null === $id) {
      return null;
    }

    $orderQuery = OrderQuery::create();

    $order = $orderQuery->findPk($id);

    return $this->getExtendOrderProductInfo($order);
  }

  public function getExtendOrderProductInfo(\Thelia\Model\Order $order)
  {
    $orderProducts = [];
    $orderProductQuery = OrderProductQuery::create();
    $orderProductTaxJoin = new Join(
      OrderProductTableMap::COL_ID,
      OrderProductTaxTableMap::COL_ORDER_PRODUCT_ID,
      Criteria::LEFT_JOIN
    );

    $orderProductQuery
      ->addJoinObject($orderProductTaxJoin)
      ->addAsColumn(
        'taxAmount',
        OrderProductTaxTableMap::COL_AMOUNT
      )->addAsColumn(
        'promoTaxAmount',
        OrderProductTaxTableMap::COL_PROMO_AMOUNT
      )
      ->findByOrderId($order->getId());

    foreach ($orderProductQuery as $product) {
      $pseQuery = ProductSaleElementsQuery::create();

      $pse = $pseQuery->findPk($product->getProductSaleElementsId());

      $orderProducts[] = [
        ...$product->toArray(TableMap::TYPE_CAMELNAME),
        'productId' => $pse->getProductId(),
        'attributesAv' => $this->pseService->getAttributesAvFromPse($pse),
      ];
    };
    $orderExtend = [
      ...$order->toArray(TableMap::TYPE_CAMELNAME),
      'orderProducts' => $orderProducts
    ];


    return $orderExtend;
  }
}
