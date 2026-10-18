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

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Where the payment options the buyer picked wait for the payment module.
 *
 * The order button of the payment step is a link to /checkout/pay, not a form: what the
 * buyer picks has to be known before it is clicked. It is kept in the session under
 * `payment_module_option_choices`, the key payment modules already read it from, as a
 * list of `{group, values}` entries. The module reads it in its pay() method; no entry
 * for one of its groups means the buyer picked nothing there.
 *
 * Nothing is kept without a session (a console command, a request with none).
 */
final readonly class PaymentOptionChoiceStore
{
    public const string SESSION_KEY = 'payment_module_option_choices';

    public function __construct(private RequestStack $requestStack)
    {
    }

    /**
     * @return list<array{group: string, values: list<string>}>
     */
    public function all(): array
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request || !$request->hasSession()) {
            return [];
        }

        $choices = $request->getSession()->get(self::SESSION_KEY, []);

        if (!\is_array($choices)) {
            return [];
        }

        $valid = [];

        foreach ($choices as $choice) {
            if (\is_array($choice) && \is_string($choice['group'] ?? null) && \is_array($choice['values'] ?? null)) {
                $valid[] = [
                    'group' => $choice['group'],
                    'values' => array_values(array_filter($choice['values'], 'is_string')),
                ];
            }
        }

        return $valid;
    }

    /**
     * @param list<array{group: string, values: list<string>}> $choices
     */
    public function replace(array $choices): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request || !$request->hasSession()) {
            return;
        }

        if ([] === $choices) {
            $request->getSession()->remove(self::SESSION_KEY);

            return;
        }

        $request->getSession()->set(self::SESSION_KEY, $choices);
    }
}
