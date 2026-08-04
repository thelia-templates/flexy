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

namespace FlexyBundle\Service;

use Thelia\Core\HttpFoundation\Session\Session;

readonly class DeliveryService
{
    public function __construct(private Session $session)
    {
    }

    public function setPickupSession(?array $pickup): void
    {
        if ($pickup) {
            $this->session->set('pickup_address', $pickup['address']);
            $this->session->set('pickup', $pickup);

            return;
        }

        $this->session->remove('pickup_address');
        $this->session->remove('pickup');
    }
}
