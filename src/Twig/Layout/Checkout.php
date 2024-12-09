<?php

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlexyBundle\Twig\Layout;

use Propel\Runtime\Map\TableMap;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Service\Model\CartService;
use TwigEngine\Service\DataAccess\AttributeAccessService;
use TwigEngine\Service\DataAccess\DataAccessService;

#[AsLiveComponent(template: '@components/Layout/Checkout/Checkout.html.twig')]
class Checkout
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $page = 'cart';

    #[LiveProp]
    public int $step = 1;

    #[LiveProp]
    public array $cart;

    #[LiveProp]
    public array $summary = [
        'item_count' => null,
        'total_price_without_discount' => null,
        'total_taxed_price' => null,
        'total_tax_amount' => null,
    ];

    public function __construct(
        private readonly DataAccessService $dataAccessService,
        private readonly AttributeAccessService $attributeAccessService,
        private readonly CartService $cartService
    ) {
    }

    public function mount(string $page, string $step): void
    {
        $this->page = $page;
        $this->step = (int) $step;
        $this->setCart();
        $this->setSummary();
    }

    #[LiveListener('resetCart')]
    public function resetCart(): void
    {
        $this->setCart();
    }

    #[LiveListener('resetSummary')]
    public function resetSummary(): void
    {
        $this->setSummary();
    }

    public function getCart(): array
    {
        return $this->cart;
    }

    protected function setCart(): void
    {
        $sessionCart = $this->cartService->getCart();

        $items = $sessionCart->getCartItems();

        $this->cart = [...$sessionCart->toArray(TableMap::TYPE_CAMELNAME), 'items' => $items->toArray(null, false, TableMap::TYPE_CAMELNAME)];
    }

    protected function setSummary(): void
    {
        foreach ($this->summary as $key => &$value) {
            $value = $this->attributeAccessService->attributeCart($key);
        }
    }
}
