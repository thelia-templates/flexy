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

namespace FlexyBundle\Components\Organisms\DeliveryEstimate;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The delivery a product page announces. Counted once by the product details when the
 * page is drawn (FlexyBundle\Service\DeliveryEstimateResolver) and handed over here: the
 * details re-render on every change of combination or quantity, and the estimate depends
 * on neither.
 */
#[AsTwigComponent]
class Base
{
    /** @var array{earliest: string, latest: string, country: string}|null */
    public ?array $estimate = null;
}
