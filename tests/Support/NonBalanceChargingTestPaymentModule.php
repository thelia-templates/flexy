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

namespace FlexyBundle\Tests\Support;

use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Model\Order;
use Thelia\Module\AbstractPaymentModule;

/**
 * A payment provider that cannot charge the balance an instant part leaves: it does not
 * implement BalanceChargingPaymentModuleInterface, and its class is what says so.
 *
 * The scenarios that need such a provider install this one instead of leaning on a module of
 * the shop, whose capability changes from one release of the module to the next.
 */
final class NonBalanceChargingTestPaymentModule extends AbstractPaymentModule
{
    public function destroy(?ConnectionInterface $con = null, $deleteModuleData = false): void
    {
    }

    public function pay(Order $order): ?Response
    {
        return null;
    }

    public function isValidPayment(): bool
    {
        return true;
    }
}
