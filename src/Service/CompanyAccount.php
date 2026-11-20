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

use FlexyBundle\DTO\CompanyAccountView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Company\CompanyPermission;
use Thelia\Domain\Company\DTO\CompanyMembership;
use Thelia\Domain\Company\Service\CompanyMembershipReader;
use Thelia\Domain\Company\Service\CompanyPermissionChecker;
use Thelia\Model\Company;
use Thelia\Model\CompanyQuery;
use Thelia\Model\Customer;
use Thelia\Model\Order;

/**
 * What the company of the signed-in customer lets them do in the account: the single place the
 * pages, the menu and the templates ask, so that no template compares a role.
 *
 * The answers come from the core: the membership is read once per request, and the permission
 * checker is the one judge. A customer who belongs to no company, a guest and a visitor are
 * not members, and hold no company permission.
 */
final readonly class CompanyAccount
{
    /** @var \WeakMap<Request, CompanyAccountView> */
    private \WeakMap $viewByRequest;

    public function __construct(
        private SecurityContext $securityContext,
        private CompanyMembershipReader $membershipReader,
        private CompanyPermissionChecker $permissionChecker,
        private RequestStack $requestStack,
    ) {
        $this->viewByRequest = new \WeakMap();
    }

    /**
     * Everything a page asks about the company, decided once per request: the key is the request
     * itself, held weakly, so a worker never hands one customer's answers to the next one.
     */
    public function view(): CompanyAccountView
    {
        $request = $this->requestStack->getMainRequest();

        if (null === $request) {
            return $this->decide();
        }

        return $this->viewByRequest[$request] ??= $this->decide();
    }

    private function decide(): CompanyAccountView
    {
        $membership = $this->membership();

        if (null === $membership) {
            return new CompanyAccountView();
        }

        return new CompanyAccountView(
            member: true,
            companyName: $this->company()?->getName(),
            suspended: !$membership->companyActive,
            canEditCompany: $this->can(CompanyPermission::COMPANY_EDIT),
            canManageBuyers: $this->can(CompanyPermission::BUYER_MANAGE),
            canViewCompanyOrders: $this->can(CompanyPermission::ORDER_VIEW_COMPANY),
            canManageSharedAddresses: $this->can(CompanyPermission::ADDRESS_MANAGE_SHARED),
        );
    }

    public function customer(): ?Customer
    {
        $customer = $this->securityContext->getCustomerUser();

        return $customer instanceof Customer && !$customer->isGuest() ? $customer : null;
    }

    public function membership(): ?CompanyMembership
    {
        $customer = $this->customer();

        return null === $customer ? null : $this->membershipReader->membershipOf($customer);
    }

    public function isMember(): bool
    {
        return null !== $this->membership();
    }

    /**
     * Whether the signed-in customer holds the permission within the company they belong to.
     */
    public function can(string $permission): bool
    {
        $customer = $this->customer();
        $membership = $this->membership();

        return null !== $customer && null !== $membership && $this->permissionChecker->can($customer, $permission, $membership->companyId);
    }

    /**
     * Whether the signed-in customer reads this order: the one they placed, or, with the permission
     * to read the orders of their company, any order placed for it. The core is the judge.
     */
    public function canAccessOrder(Order $order): bool
    {
        $customer = $this->customer();

        return null !== $customer && $this->permissionChecker->canAccessOrder($customer, $order);
    }

    /**
     * A suspended company keeps its buyers signed in and its history readable, and places no order.
     */
    public function isSuspended(): bool
    {
        $membership = $this->membership();

        return null !== $membership && !$membership->companyActive;
    }

    public function company(): ?Company
    {
        $membership = $this->membership();

        return null === $membership ? null : CompanyQuery::create()->findPk($membership->companyId);
    }
}
