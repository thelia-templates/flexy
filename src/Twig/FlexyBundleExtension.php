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

namespace FlexyBundle\Twig;

use FlexyBundle\Service\ProductSaleElementsService;
use Thelia\Core\Security\SecurityContext;
use Thelia\Model\Customer;
use Thelia\Model\ProductSaleElements;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class FlexyBundleExtension extends AbstractExtension
{
    public function __construct(
        private ProductSaleElementsService $pseService,
        public SecurityContext $securityContext,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('attributeAv', [$this, 'attributeAv']),
            new TwigFunction('getCurrentCustomer', [$this, 'getCurrentCustomer']),
        ];
    }

    public function getCurrentCustomer(): ?Customer
    {
        return $this->securityContext->getCustomerUser();
    }

    public function attributeAv(ProductSaleElements $pse): array
    {
        if (null === $pse) {
            return [];
        }

        return $this->pseService->getAttributesAvFromPse($pse);
    }
}
