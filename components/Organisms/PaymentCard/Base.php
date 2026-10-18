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

namespace FlexyBundle\Components\Organisms\PaymentCard;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use Thelia\Domain\Cart\CartFacade;

#[AsTwigComponent]
class Base
{
    public ?array $module = null;
    public bool $checked = false;

    /**
     * The options of this module the buyer has picked, as the session holds them.
     *
     * @var list<array{group: string, values: list<string>}>
     */
    public array $optionChoices = [];

    public function __construct(private readonly CartFacade $cartFacade)
    {
    }

    #[PostMount]
    public function markCheckedFromCart(): void
    {
        $this->checked = null !== $this->module
            && $this->module['id'] === $this->cartFacade->getPaymentModuleId();
    }

    public function isOptionPicked(string $groupCode, string $optionCode): bool
    {
        foreach ($this->optionChoices as $choice) {
            if ($choice['group'] === $groupCode) {
                return \in_array($optionCode, $choice['values'], true);
            }
        }

        return false;
    }
}
