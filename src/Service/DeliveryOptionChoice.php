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

/**
 * Whether a delivery option posted by the page is one the cart can be shipped with.
 *
 * The delivery step takes the option code and the module id from a live event: both come
 * from the browser, and any module of the shop could be named, an inactive one, one that
 * does not serve the country of the address, a payment module. Only an option the shipping
 * facade offers for this cart is written to it.
 *
 * A decision and nothing else, so that it can be read and tested on its own.
 */
final readonly class DeliveryOptionChoice
{
    /**
     * @param array<array-key, array<string, mixed>> $offered the options of the cart, as
     *                                                       Delivery\Base::getDeliveryModulesOptions() answers them
     */
    public static function isOffered(array $offered, string $optionCode, int $moduleId): bool
    {
        foreach ($offered as $option) {
            if ($option['code'] === $optionCode && (int) $option['moduleId'] === $moduleId) {
                return true;
            }
        }

        return false;
    }
}
