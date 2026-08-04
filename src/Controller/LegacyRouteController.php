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

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Front module still declares the Thelia 2 URLs (front.xml) in its own
 * router and renders Smarty-era view names this theme does not ship, which
 * ends in a 500. Declaring the same paths here wins over the Front module
 * router (the default router is matched first) and sends visitors to the
 * Flexy equivalent. POST counterparts are intentionally left to the Front
 * module: no Flexy template posts to them.
 */
class LegacyRouteController extends FlexyController
{
    #[Route('/register', name: 'legacy_register', methods: ['GET'])]
    public function register(): RedirectResponse
    {
        return $this->generateRedirect($this->generateUrl('customer_register'), 301);
    }

    #[Route('/account/update', name: 'legacy_account_update', methods: ['GET'])]
    public function accountUpdate(): RedirectResponse
    {
        return $this->generateRedirect($this->generateUrl('account_index'), 301);
    }

    #[Route('/address/create', name: 'legacy_address_create', methods: ['GET'])]
    public function addressCreate(): RedirectResponse
    {
        return $this->generateRedirect($this->generateUrl('account_address_new'), 301);
    }
}
