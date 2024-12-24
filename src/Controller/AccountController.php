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

use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\HttpFoundation\Response;

// decorateur custom
#[Route('/account', name: 'account_')]
class AccountController extends BaseFrontController
{

  #[Route('', name: 'no_route')]
  public function noRouteAction(): Response
  {

    $this->checkAuth();

    return $this->render('account', [
      'customer' => 'toto',
    ]);
  }

  #[Route('/orders', name: 'account_orders')]
  public function ordersAction(): Response
  {

    $this->checkAuth();

    return $this->render('account-orders', [
      'customer' => 'toto',
    ]);
  }
}
