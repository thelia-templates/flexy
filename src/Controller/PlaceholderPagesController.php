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
 * Route placeholders for pages not migrated yet (Account, Cart, Checkout), so that
 * Header/Footer links relying on these route names resolve instead of throwing.
 */
final class PlaceholderPagesController
{
    #[Route('/account', name: 'account_index')]
    #[Route('/account/orders', name: 'account_orders')]
    #[Route('/account/addresses', name: 'account_addresses')]
    public function __invoke(): Response
    {
        return new Response('Coming soon.');
    }
}
