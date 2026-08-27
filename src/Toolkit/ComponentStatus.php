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

/**
 * Where each story stands, keyed by its path under `components/`. An absent story claims
 * nothing: it is neither vouched for nor a backlog entry. This theme is a starting point,
 * so the list ships empty on purpose — a project fills it as its own work closes.
 *
 * READY means handled on our side and open to review, not yet reviewed.
 *
 * WAITING means the work is stopped on something that is not ours to decide — a screen not
 * drawn, a usage to confirm. The story then carries a `Story-warning` banner saying which,
 * because a status alone cannot say what is being waited for.
 *
 * HIDDEN means the component will never be integrated: its story is dropped from the
 * toolkit. The files stay, and so does its CSS import — until the last call site is gone,
 * removing the import would leave the component rendering unstyled somewhere.
 */
final class ComponentStatus
{
    public const string READY = 'ready';
    public const string WAITING = 'waiting';
    public const string HIDDEN = 'hidden';

    /**
     * The short arms come first so that what is not ready reads in a glance.
     *
     * The `default` arm carries the rule the docblock states, and keeps a story nobody
     * listed from raising an UnhandledMatchError.
     */
    public static function of(string $path): ?string
    {
        return match ($path) {
            // Stories stopped on a decision that is not the theme's to make:
            // 'Molecules/Example' => self::WAITING,

            // Components that will never be integrated, and whose story is dropped:
            // 'Organisms/Example' => self::HIDDEN,

            // The guide waits on the one thing the theme cannot do for a project: being read,
            // then removed. Deleting `components/Toolkit/getting-started.html.twig` retires the
            // page; remove this arm and its SECTIONS entry by hand — nothing here does it for you.
            'Toolkit/getting-started' => self::WAITING,

            // The two entries this theme ships, so the mechanism is shown in place rather than
            // only described. `welcome` is meant to be rewritten by the project, not deleted.
            // Keys: a component is keyed by its directory (`Molecules/Button`), a toolkit page by
            // its file without extension (`Toolkit/welcome`).
            'Toolkit/welcome' => self::READY,

            default => null,
        };
    }
}
