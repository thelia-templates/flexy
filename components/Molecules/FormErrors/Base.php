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

use Symfony\Component\Form\FormError;
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

    /** @var list<string> */
    public array $sampleFormFailures = [];

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

    /**
     * What the form itself refused, a rejected CSRF token for one. The cause is what tells these
     * apart from the wrapped message, and none repeats a field, so they show whatever else is wrong.
     *
     * @return list<string>
     */
    public function formFailures(): array
    {
        if (!$this->form instanceof FormView) {
            return $this->sampleFormFailures;
        }

        return self::messagesOf($this->form, static fn (FormError $error): bool => null !== $error->getCause());
    }

    /**
     * The message Thelia wraps around a rejected submission: `BaseForm::setErrorMessage()` stores
     * it, `FormService` turns it into a root error with no cause. It repeats what the fields
     * already show, so it only appears when no field shows anything.
     *
     * @return list<string>
     */
    public function rootErrors(): array
    {
        if (!$this->form instanceof FormView) {
            return $this->sampleRootErrors;
        }

        return self::messagesOf($this->form, static fn (FormError $error): bool => null === $error->getCause());
    }

    /**
     * @param callable(FormError): bool $keep
     *
     * @return list<string>
     */
    private static function messagesOf(FormView $form, callable $keep): array
    {
        $messages = [];

        foreach ($form->vars['errors'] ?? [] as $error) {
            if ($error instanceof FormError && $keep($error)) {
                $messages[] = $error->getMessage();
            }
        }

        return $messages;
    }

    public function hasErrors(): bool
    {
        return [] !== $this->entries() || [] !== $this->formFailures() || [] !== $this->rootErrors();
    }

    /**
     * A single visible field reads under itself; anything else needs the summary. The wrapped
     * message only counts when nothing else is on show: Thelia sets it on every rejected
     * submission, so counting it regardless would give every form a summary.
     */
    public function showSummary(): bool
    {
        if ([] !== $this->formFailures()) {
            return true;
        }

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
