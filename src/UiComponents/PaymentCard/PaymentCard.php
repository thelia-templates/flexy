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

namespace FlexyBundle\UiComponents\PaymentCard;

use FlexyBundle\UiComponents\Checkout\CheckoutEvents;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Checkout\DTO\CheckoutDTO;

#[AsTwigComponent(name: 'Flexy:PaymentCard', template: '@UiComponents/PaymentCard/PaymentCard.html.twig')]
class PaymentCard
{
    public ?array $module;

    public bool $checked = false;

    public function __construct(private readonly CartFacade $cartFacade)
    {
    }

    #[PostMount]
    public function postMount(): void
    {
        $this->checked = $this->module['id'] === $this->cartFacade->getPaymentModuleId();

    }


}
