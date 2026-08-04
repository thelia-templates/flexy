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

use Thelia\Domain\Localization\LocalizationFacade;
use Thelia\Model\AttributeCombination;
use Thelia\Model\ProductSaleElements;

readonly class ProductSaleElementsService
{
    public function __construct(
        private LocalizationFacade $localizationFacade,
    ) {
    }

    public function getAttributesAvFromPse(ProductSaleElements $pse): array
    {
        $locale = $this->localizationFacade->getCurrentLocale();

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
