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

    public function __construct(private readonly CartFacade $cartFacade)
    {
    }

    #[PostMount]
    public function markCheckedFromCart(): void
    {
        $this->checked = null !== $this->module
            && $this->module['id'] === $this->cartFacade->getPaymentModuleId();
    }
}
