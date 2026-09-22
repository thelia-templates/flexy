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

namespace FlexyBundle\Components\Organisms\VideoPlayer;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A video shown as a poster with a play button, the player itself being built by the
 * Stimulus controller on the first click. Nothing to compute server-side: the addresses
 * are decided by the core and handed over as they are.
 */
#[AsTwigComponent]
final class Base
{
}
