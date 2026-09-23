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

namespace FlexyBundle\Components\Molecules\FormErrors;

use Symfony\Component\Form\FormView;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Reads every error a submitted form carries, so a form can show them all at once.
 *
 * Injected by the `form_start` block of the form theme, so it runs on every form of the theme,
 * submitted or not: a form without errors has to cost nothing at all.
 */
#[AsTwigComponent]
class Base
{
    public ?FormView $form = null;

    /**
     * Entries handed over directly, for the story: the toolkit has no submitted form to read.
     *
     * @var list<array{label: ?string, messages: list<string>, target: ?string, hidden: bool}>
     */
    public array $sampleEntries = [];

    /** @var list<string> */
    public array $sampleRootErrors = [];

    /**
     * One entry per field in error, in the order the form declares its fields, carrying every
     * message it collected. A hidden field gets neither label nor anchor: the summary is the
     * only place it is ever read.
     *
     * @return list<array{label: ?string, messages: list<string>, target: ?string, hidden: bool}>
     */
    public function entries(): array
    {
        if (!$this->form instanceof FormView) {
            return $this->sampleEntries;
        }

        return $this->collect($this->form);
    }

    /** @return list<string> */
    public function rootErrors(): array
    {
        if (!$this->form instanceof FormView) {
            return $this->sampleRootErrors;
        }

        $messages = [];

        foreach ($this->form->vars['errors'] ?? [] as $error) {
            $messages[] = $error->getMessage();
        }

        return $messages;
    }

    public function hasErrors(): bool
    {
        return [] !== $this->entries() || [] !== $this->rootErrors();
    }

    /**
     * A single visible field reads under itself; anything else needs the summary to be seen.
     *
     * Root errors only count when they are the ones on show: Thelia controllers set one on every
     * rejected submission, so counting them regardless would give every form a summary.
     */
    public function showSummary(): bool
    {
        $entries = $this->entries();

        if (\count($entries) > 1) {
            return true;
        }

        if ([] === $entries) {
            return [] !== $this->rootErrors();
        }

        foreach ($entries as $entry) {
            if ($entry['hidden']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{label: ?string, messages: list<string>, target: ?string, hidden: bool}>
     */
    private function collect(FormView $form): array
    {
        $entries = [];

        foreach ($form->children as $child) {
            $messages = [];

            foreach ($child->vars['errors'] ?? [] as $error) {
                $messages[] = $error->getMessage();
            }

            if ([] !== $messages) {
                $hidden = \in_array('hidden', $child->vars['block_prefixes'] ?? [], true);

                $entries[] = [
                    'label' => $hidden ? null : self::labelOf($child),
                    'messages' => $messages,
                    'target' => $hidden ? null : ($child->vars['id'] ?? null),
                    'hidden' => $hidden,
                ];
            }

            foreach ($this->collect($child) as $descendant) {
                $entries[] = $descendant;
            }
        }

        return $entries;
    }

    /** Only a declared label: humanising the name would put untranslated English beside a translated field. */
    private static function labelOf(FormView $field): ?string
    {
        $label = $field->vars['label'] ?? null;

        return \is_string($label) && '' !== trim($label) ? $label : null;
    }
}
