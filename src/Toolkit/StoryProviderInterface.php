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

namespace FlexyBundle\Toolkit;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What a module implements to list its components in the toolkit.
 *
 * The toolkit walks the theme's `components/` directory and nothing else, so a component a
 * module brings is invisible to it unless the module says so here. Every provider is asked
 * once per toolkit page; the tag priority sets the order the stories are listed in, and a
 * story whose status is HIDDEN is left out like a theme story would be.
 *
 * The toolkit answers only where the kernel runs in debug, so a provider is never called on
 * a live shop.
 */
#[AutoconfigureTag('flexy.toolkit_story_provider')]
interface StoryProviderInterface
{
    /**
     * @return list<Story>
     */
    public function stories(): array;
}
