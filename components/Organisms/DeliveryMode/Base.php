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

namespace FlexyBundle\Components\Organisms\DeliveryMode;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
class Base
{
    public string $type = 'DeliveryMode';
    public int $moduleId = 0;
    public string $optionCode = '';
    public string $title = '';
    public string $description = '';
    public string $price = '';
    public bool $checked = false;
}
