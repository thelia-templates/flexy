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

namespace FlexyBundle\DTO;

/**
 * What the company of the signed-in customer lets them see and do in the account, decided once
 * per request by the core and read by the pages, the menu and the templates.
 */
final readonly class CompanyAccountView
{
    public function __construct(
        public bool $member = false,
        public ?string $companyName = null,
        public bool $suspended = false,
        public bool $canEditCompany = false,
        public bool $canManageBuyers = false,
        public bool $canViewCompanyOrders = false,
        public bool $canManageSharedAddresses = false,
    ) {
    }
}
