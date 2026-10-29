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

namespace FlexyBundle\Components\Organisms\BundleComposition;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * What a bundle is made of, as its sheet lists it: the components that cannot be changed, the optional
 * ones to tick, the ones whose quantity the shopper picks within the bounds of the merchant.
 *
 * Draws only. The state lives in the LiveComponent of the sheet (Layouts/ProductDetails), whose actions and
 * model the fields below are bound to: the price and the add to the cart that follow are drawn from the same
 * state, and a component of its own would need a round trip to the sheet to agree with it.
 */
#[AsTwigComponent]
class Base {}
