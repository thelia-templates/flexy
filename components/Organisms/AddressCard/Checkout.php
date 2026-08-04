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

namespace FlexyBundle\Components\Organisms\AddressCard;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use Thelia\Domain\Cart\CartFacade;

#[AsTwigComponent]
class Checkout extends Base
{
    public string $type = 'delivery';

    public function __construct(private readonly CartFacade $cartFacade)
    {
    }

    #[PostMount]
    public function markCheckedFromCart(): void
    {
        $savedAddressId = 'delivery' === $this->type
            ? $this->cartFacade->getDeliveryAddressId()
            : $this->cartFacade->getInvoiceAddressId();

        $this->checked = null !== $savedAddressId && $savedAddressId === $this->addressId;
    }
}
