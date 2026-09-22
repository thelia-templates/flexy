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
     * Entries handed over directly. The toolkit has no submitted form to read, and the three
     * renderings below are what its story has to show.
     *
     * @var list<array{label: ?string, message: string, target: ?string, hidden: bool}>
     */
    public array $sampleEntries = [];

    /** @var list<string> */
    public array $sampleRootErrors = [];

    /**
     * One entry per field error, in the order the form declares its fields. A hidden field
     * carries neither label nor anchor: the summary is the only place it is ever read.
     *
     * @return list<array{label: ?string, message: string, target: ?string, hidden: bool}>
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
     * A single error on a visible field reads under that field: repeating it above would say
     * the same thing twice. Anything else needs the summary to be seen at all.
     *
     * Root errors only count when they are the ones on show. Thelia controllers set one on
     * every rejected submission, so counting them regardless would give a summary to every
     * form, including the single visible error that is meant to do without one.
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
     * @return list<array{label: ?string, message: string, target: ?string, hidden: bool}>
     */
    private function collect(FormView $form): array
    {
        $entries = [];

        foreach ($form->children as $child) {
            $hidden = \in_array('hidden', $child->vars['block_prefixes'] ?? [], true);

            foreach ($child->vars['errors'] ?? [] as $error) {
                $entries[] = [
                    'label' => $hidden ? null : self::labelOf($child),
                    'message' => $error->getMessage(),
                    'target' => $hidden ? null : ($child->vars['id'] ?? null),
                    'hidden' => $hidden,
                ];
            }

            $entries = [...$entries, ...$this->collect($child)];
        }

        return $entries;
    }

    private static function labelOf(FormView $field): ?string
    {
        $label = $field->vars['label'] ?? null;

        if (\is_string($label) && '' !== trim($label)) {
            return $label;
        }

        $name = $field->vars['name'] ?? null;

        return \is_string($name) && '' !== $name
            ? ucfirst(str_replace(['_', '-'], ' ', $name))
            : null;
    }
}
