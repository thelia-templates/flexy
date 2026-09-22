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

use Symfony\Component\Form\FormView;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Overrides on the FormView the labels a form type declared, so the page and anything reading
 * the view say the same thing.
 *
 * `form_row(form.x, {label: …})` cannot: that argument reaches the renderer, which copies the
 * view vars into a local scope and leaves the view untouched. Whatever reads the form rather
 * than rendering it — the error summary, for one — then reads the other label.
 *
 * Nothing is persisted; the call is replayed on every render, which is what makes it hold
 * across a live re-render and a dependent field being rebuilt.
 */
class OverrideLabelsExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('override_labels', $this->overrideLabels(...)),
        ];
    }

    /**
     * @param array<string, string> $labels keyed by child name, or by a dotted path for a nested
     *                                      child such as `password.first`; an unknown name is ignored
     */
    public function overrideLabels(FormView $form, array $labels): void
    {
        // Called after form_start, the write lands too late for what that block rendered, and
        // the page says two different things in silence — the very defect this exists to remove.
        if ($form->isMethodRendered()) {
            throw new \LogicException(
                'override_labels() must be called before form_start(), otherwise the labels it '
                . 'writes are ignored by everything form_start() has already rendered.',
            );
        }

        foreach ($labels as $path => $label) {
            $child = self::childAt($form, $path);

            if (!$child instanceof FormView) {
                continue;
            }

            // `false` means the form asked for no label at all; that is a decision, not a gap.
            if (false === ($child->vars['label'] ?? null)) {
                continue;
            }

            $child->vars['label'] = $label;
        }
    }

    /**
     * A dotted path is read one child at a time, so that a repeated field reaches its halves
     * without the caller handing over a sub-view: the guard above only knows the root.
     */
    private static function childAt(FormView $form, string $path): ?FormView
    {
        $view = $form;

        foreach (explode('.', $path) as $name) {
            $view = $view->children[$name] ?? null;

            if (!$view instanceof FormView) {
                return null;
            }
        }

        return $view;
    }
}
