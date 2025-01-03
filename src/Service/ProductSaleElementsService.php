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

namespace FlexyBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\AttributeCombination;
use Thelia\Model\ProductSaleElements;

class ProductSaleElementsService
{
  public function __construct(private readonly RequestStack $requestStack) {}

  public function getAttributesAvFromPse(ProductSaleElements $pse): array
  {
    $request = $this->requestStack->getCurrentRequest();

    /** @var ?Session $session */
    $session = $request?->getSession();

    $locale = $session?->getLang()->getLocale();

    $combinations = $pse->getAttributeCombinations();
    $attributesAv = [];


    /** @var AttributeCombination $combination */
    foreach ($combinations as $combination) {
      $title = $combination->getAttribute()->setLocale($locale)->getTitle();
      $av = $combination->getAttributeAv()->setLocale($locale)->getTitle();

      $attributesAv[$title] = $av;
    }

    return $attributesAv;
  }
}
