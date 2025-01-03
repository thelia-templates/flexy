<?php

namespace FlexyBundle\Twig;

use FlexyBundle\Service\ProductSaleElementsService;
use Thelia\Model\ProductSaleElements;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class FlexyBundleExtension extends AbstractExtension
{

  public function __construct(private ProductSaleElementsService $pseService) {}

  public function getFunctions(): array
  {
    return [
      new TwigFunction('attributeAv', [$this, 'attributeAv']),
    ];
  }

  public function attributeAv(ProductSaleElements $pse): array
  {
    
    if (null === $pse) {
      return [];
    }

    return $this->pseService->getAttributesAvFromPse($pse);
  }
}
