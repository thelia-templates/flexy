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

namespace FlexyBundle\Twig\Organisms;

use FlexyBundle\Service\ProductSaleElementsService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Model\ProductSaleElementsQuery;

#[AsLiveComponent(template: '@components/Organisms/AddToCartToast/AddToCartToast.html.twig')]
class AddToCartToast extends BaseFrontController
{
    use DefaultActionTrait;

    #[LiveProp]
    public ?int $quantity = null;
    #[LiveProp]
    public ?int $pseId = null;
    #[LiveProp]
    public ?string $title = null;
    #[LiveProp]
    public ?string $secondaryTitle = null;

    #[LiveProp]
    public ?array $attributesAv = null;

    public function __construct(private ProductSaleElementsService $pseService)
    {
    }

    #[LiveListener('addToCart')]
    public function addToCart(#[LiveArg] array $values): void
    {
        $this->quantity = (int) $values['quantity'];
        $this->pseId = (int) $values['product_sale_elements_id'];

        $pse = ProductSaleElementsQuery::create()->findPk($this->pseId);
        $this->title = $pse->getProduct()->getTitle();
        $this->secondaryTitle = $pse->getProduct()->getChapo();

        $this->attributesAv = $this->pseService->getAttributesAvFromPse($pse);
    }

    #[LiveAction]
    public function closeToast(): void
    {
        $this->resetValues();
    }

    #[LiveAction]
    public function viewCart(): Response
    {
        return $this->generateRedirect('checkout/cart');
    }

    protected function resetValues(): void
    {
        $this->quantity = null;
        $this->pseId = null;
        $this->title = null;
        $this->secondaryTitle = null;
        $this->attributesAv = null;
    }
}
