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

namespace FlexyBundle\Controller;

use FlexyBundle\Service\OrderService;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\HttpFoundation\Response;

#[Route('/account', name: 'account_')]
class AccountController extends BaseFrontController
{
  public function __construct(private OrderService $orderService) {}

  #[Route('', name: 'no_route')]
  public function noRouteAction(): Response
  {
    $this->checkAuth();

    return $this->render('account', [
      'customer' => 'toto',
    ]);
  }
  #[Route('/order/{orderId}', name: 'account_order', requirements: ['orderId' => '\d+'])]
  public function orderAction(int $orderId = null): Response
  {
    $this->checkAuth();

    return $this->render('account-order', [
      'orderId' => $orderId
    ]);
  }
}
