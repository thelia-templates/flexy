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

namespace FlexyBundle\Service\Customer;

use Propel\Runtime\Exception\PropelException;
use Thelia\Mailer\MailerFactory;
use Thelia\Model\Customer;

readonly class CustomerCodeProcessor
{
    public function __construct(
        private MailerFactory $mailerFactory,
    ) {
    }

    /**
     * @throws PropelException
     */
    public function createCodeAndSendIt(
        Customer $customer,
        int $expiryTimeInHours = 24,
    ): void {
        $code = $customer->setConfirmationTokenWithExpiry($expiryTimeInHours);
        $customer->save();

        $this->mailerFactory->sendEmailToCustomer(
            'customer_send_code',
            $customer,
            [
                'code' => $code,
                'expiryTime' => $expiryTimeInHours,
                'customer' => $customer,
            ]
        );
    }
}
