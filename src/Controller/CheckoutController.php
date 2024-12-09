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

#[Route('/checkout', name: 'checkout_')]
class CheckoutController extends BaseFrontController
{
    public const STEP_CART = 'cart';
    public const STEP_DELIVERY = 'delivery';
    public const STEPS = [
      self::STEP_CART => 1,
      self::STEP_DELIVERY => 2,
    ];

    #[Route('', name: 'no_route')]
    public function noRouteAction(): Response
    {
        return $this->pageNotFound();
    }

    #[Route('/cart', name: 'cart')]
    public function cartAction(): Response
    {
        return $this->render('checkout', [
          'page' => self::STEP_CART,
          'current' => self::STEPS[self::STEP_CART],
        ]);
    }

    #[Route('/delivery', name: 'delivery')]
    public function deliveryAction(): Response
    {
        return $this->render('checkout', [
          'page' => self::STEP_DELIVERY,
          'current' => self::STEPS[self::STEP_DELIVERY],
        ]);
    }
}
