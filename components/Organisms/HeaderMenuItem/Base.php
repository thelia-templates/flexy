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

namespace FlexyBundle\Components\Organisms\HeaderMenuItem;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
class Base
{
    public int|string|null $id = null;
    public string $menuKey = '';
    public string $title = '';
    public string $href = '';
    public bool $opensInNewWindow = false;
    public array $columns = [];
    public array $leafLinks = [];
    public bool $showSeeMore = false;
}
