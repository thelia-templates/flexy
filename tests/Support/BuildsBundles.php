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

namespace FlexyBundle\Tests\Support;

use Thelia\Domain\Catalog\Bundle\BundleCompositionService;
use Thelia\Domain\Catalog\Bundle\BundleDiscountType;
use Thelia\Domain\Catalog\Bundle\BundlePriceMode;
use Thelia\Model\Country;
use Thelia\Model\Product;
use Thelia\Model\ProductBundleItem;
use Thelia\Model\TaxRule;
use Thelia\Model\TaxRuleCountry;
use Thelia\Test\FixtureFactory;

/**
 * The bundle of the plan, for a test of the theme: a bike (1, required, 500, stock 7), helmets (2, required,
 * 30, stock 5) and a lock (1 by default, optional, 0 to 3, 20, stock 10), priced by its components with 10 %
 * off, and taxed at 20 % like the product of the bundle.
 *
 * Without the lock the components cost 560 and the bundle 504; with two locks 600 and 540. Taxes included
 * that is 672 and 604.80, then 720 and 648.
 */
trait BuildsBundles
{
    /**
     * @return array{bundle: Product, bike: ProductBundleItem, helmet: ProductBundleItem, lock: ProductBundleItem, bikeProduct: Product, helmetProduct: Product, lockProduct: Product}
     */
    private function exampleBundle(FixtureFactory $factory, BundleCompositionService $service, BundlePriceMode $mode = BundlePriceMode::Computed): array
    {
        $category = $factory->category();
        $currency = $factory->currency();
        $plain = $factory->taxRule();
        $bikeProduct = $factory->product($category, $plain, $currency, ['title' => 'Mountain bike', 'basePrice' => 500.0, 'baseQuantity' => 7]);
        $helmetProduct = $factory->product($category, $plain, $currency, ['title' => 'Helmet', 'basePrice' => 30.0, 'baseQuantity' => 5]);
        $lockProduct = $factory->product($category, $plain, $currency, ['title' => 'Chain lock', 'basePrice' => 20.0, 'baseQuantity' => 10]);
        $bundle = $factory->product($category, $this->taxingAt($factory, '20'), $currency, ['title' => 'Bike pack', 'basePrice' => 999.0, 'baseQuantity' => 0]);
        $bundle = $service->setBundleSettings((int) $bundle->getId(), true, $mode, '10', BundleDiscountType::Percent);

        return [
            'bundle' => $bundle,
            'bike' => $service->addItem((int) $bundle->getId(), $this->saleElementsIdOf($bikeProduct), '1'),
            'helmet' => $service->addItem((int) $bundle->getId(), $this->saleElementsIdOf($helmetProduct), '2'),
            'lock' => $service->addItem((int) $bundle->getId(), $this->saleElementsIdOf($lockProduct), '1', '0', '3', false),
            'bikeProduct' => $bikeProduct,
            'helmetProduct' => $helmetProduct,
            'lockProduct' => $lockProduct,
        ];
    }

    private function saleElementsIdOf(Product $product): int
    {
        return (int) $product->getProductSaleElementss()->getFirst()->getId();
    }

    private function taxingAt(FixtureFactory $factory, string $percent): TaxRule
    {
        $taxRule = $factory->taxRule(['isDefault' => false]);
        $tax = $factory->tax(['requirements' => ['percent' => $percent], 'title' => 'VAT '.$percent]);

        // The country a visitor with no address is taxed for, which is the one the pages are priced in.
        (new TaxRuleCountry())
            ->setTaxRuleId($taxRule->getId())
            ->setCountryId(Country::getDefaultCountry()->getId())
            ->setTaxId($tax->getId())
            ->setPosition(1)
            ->save();

        return $taxRule;
    }
}
