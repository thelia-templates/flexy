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
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Base\OrderProductQuery;
use Thelia\Model\Map\OrderProductTableMap;
use Thelia\Model\Map\OrderProductTaxTableMap;
use Thelia\Model\Map\ProductTaxTableMap;
use Thelia\Model\Module;
use Thelia\Model\OrderQuery;
use Thelia\Module\AbstractDeliveryModule;
use Thelia\Module\BaseModule;
use Thelia\Tools\URL;
use TwigEngine\Service\DataAccess\DataAccessService;
use Propel\Runtime\ActiveQuery\Join;
use Thelia\Model\Map\ProductSaleElementsTableMap;
use Thelia\Model\Map\ProductTableMap;

class OrderService
{
  protected $session;

  public function __construct(private readonly RequestStack $requestStack, private DataAccessService $dataAccessService)
  {
    $request = $this->requestStack->getCurrentRequest();

    /** @var Session $session */
    $this->session = $request->getSession();
  }

  public function getCustomerOrders(): array|ObjectCollection
  {
    $orderList = [];
    $search = OrderQuery::create();
    $search->findByCustomerId($this->session->getCustomerUser()->getId());

    $orders = $search->find();

    foreach ($orders as $order) {
      $orderProducts = [];
      $orderProductQuery = OrderProductQuery::create();
      $orderProductTaxJoin = new Join(
        OrderProductTableMap::COL_ID,
        OrderProductTaxTableMap::COL_ORDER_PRODUCT_ID,
        Criteria::LEFT_JOIN
      );

      $pseJoin = new Join(
        OrderProductTableMap::COL_PRODUCT_SALE_ELEMENTS_ID,
        ProductSaleElementsTableMap::COL_ID,
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
        ->addJoinObject($pseJoin)
        ->addAsColumn(
          'productId',
          ProductSaleElementsTableMap::COL_PRODUCT_ID
        )
        ->addAsColumn(
          'productSale',
          ProductSaleElementsTableMap::COL_PRODUCT_ID
        )
        ->findByOrderId($order->getId());

      foreach ($orderProductQuery->find() as $product) {
        $orderProducts[] = $product->toArray(TableMap::TYPE_CAMELNAME);
      };
      $orderList[] = [
        ...$order->toArray(TableMap::TYPE_CAMELNAME),
        'orderProducts' => $orderProducts
      ];
    }

    return $orderList;
  }
}
