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

use Thelia\Api\Service\DataAccess\DataAccessService;

/**
 * The roles a buyer who manages the buyers may hand to a colleague or an invited person: the
 * roles the company reads through the API whose permissions the buyer holds themselves.
 *
 * The API refuses a role beyond the issuer's rights as well; filtering here keeps the page from
 * offering what would be refused.
 */
final readonly class CompanyRoleChoices
{
    public function __construct(
        private DataAccessService $dataAccessService,
        private CompanyAccount $companyAccount,
    ) {
    }

    /**
     * @return array<string, string> the title of each role the signed-in buyer may give, by code
     */
    public function grantable(): array
    {
        $held = $this->companyAccount->membership()?->permissions ?? [];

        $grantable = [];

        foreach ($this->read() as $code => $role) {
            if ([] === array_diff($role['permissions'], $held)) {
                $grantable[$code] = $role['title'];
            }
        }

        return $grantable;
    }

    /**
     * @return array<string, string> the title of every role the company reads, by code
     */
    public function titles(): array
    {
        return array_map(static fn (array $role): string => $role['title'], $this->read());
    }

    /**
     * @return array<string, array{title: string, permissions: list<string>}>
     */
    private function read(): array
    {
        $roles = $this->dataAccessService->resources('/api/front/account/company/roles');
        $read = [];

        foreach (is_iterable($roles) ? $roles : [] as $role) {
            $role = (array) $role;
            $code = (string) ($role['code'] ?? '');

            if ('' === $code) {
                continue;
            }

            $read[$code] = [
                'title' => $this->titleOf($role, $code),
                'permissions' => array_values(array_map('strval', \is_array($role['permissions'] ?? null) ? $role['permissions'] : [])),
            ];
        }

        return $read;
    }

    /**
     * The in-process read gives the translation of the language of the page, as `{title}`.
     *
     * @param array<string, mixed> $role
     */
    private function titleOf(array $role, string $code): string
    {
        $title = ((array) ($role['i18ns'] ?? []))['title'] ?? null;

        return \is_string($title) && '' !== $title ? $title : $code;
    }
}
