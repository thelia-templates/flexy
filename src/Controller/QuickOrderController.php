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

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ordering by reference, from the customer account. The page only lays out the table:
 * everything it does goes through the QuickOrderTable component, which checks the
 * signed-in customer again on each of its actions.
 */
#[Route('/account', name: 'account_')]
class QuickOrderController extends FlexyController
{
    #[Route('/quick-order', name: 'quick_order', methods: ['GET'])]
    public function quickOrder(): Response
    {
        $this->checkAuth();

        return $this->render('account-quick-order');
    }
}
