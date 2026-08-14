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

namespace FlexyBundle\Twig;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\UX\TwigComponent\Event\PostRenderEvent;
use Symfony\UX\TwigComponent\Event\PreRenderEvent;

/**
 * Carries values from a component down to the components it renders, the way a compound
 * component hands its children what they need without every caller having to repeat it:
 * an accordion tells its items which accordion they belong to, and the items tell their
 * trigger and their panel which item they open.
 *
 * ux-twig-component ships `provide()` and `inject()` for this from 3.0 on, and 3.0 needs
 * PHP 8.4. Thelia runs on 8.3, so the theme carries the two functions itself. The service
 * steps aside as soon as the package provides them, see ComponentContextExtension.
 *
 * A value is scoped to the component that provided it: it is visible while that component
 * renders, including in everything it renders, and it is gone afterwards. Two accordions
 * on the same page, or one nested in another, therefore never read each other's values.
 */
class ComponentContext
{
    /**
     * One frame per component being rendered, innermost last.
     *
     * @var list<array<string, mixed>>
     */
    private array $frames = [];

    public function provide(string $key, mixed $value): void
    {
        if ([] === $this->frames) {
            // Called outside of any component: keep a frame so the value is not lost.
            $this->frames[] = [];
        }

        $this->frames[array_key_last($this->frames)][$key] = $value;
    }

    public function inject(string $key, mixed $default = null): mixed
    {
        // Innermost first: a component nested in another reads the closest value.
        foreach (array_reverse($this->frames) as $frame) {
            if (\array_key_exists($key, $frame)) {
                return $frame[$key];
            }
        }

        return $default;
    }

    #[AsEventListener(event: PreRenderEvent::class)]
    public function onPreRender(): void
    {
        $this->frames[] = [];
    }

    #[AsEventListener(event: PostRenderEvent::class)]
    public function onPostRender(): void
    {
        array_pop($this->frames);
    }
}
