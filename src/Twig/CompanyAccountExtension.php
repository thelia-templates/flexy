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

namespace FlexyBundle\Twig;

use FlexyBundle\DTO\CompanyAccountView;
use FlexyBundle\Service\CompanyAccount;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `company_account()`: what the company of the signed-in customer allows, as the core decided it
 * for this request. A template reads a permission from it and never compares a role.
 */
final class CompanyAccountExtension extends AbstractExtension
{
    public function __construct(private readonly CompanyAccount $companyAccount)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('company_account', $this->view(...)),
        ];
    }

    public function view(): CompanyAccountView
    {
        return $this->companyAccount->view();
    }
}
