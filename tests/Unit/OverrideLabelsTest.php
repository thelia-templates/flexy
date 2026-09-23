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

namespace FlexyBundle\Tests\Unit;

use FlexyBundle\Twig\OverrideLabelsExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormView;

/** What `override_labels` writes, what it leaves alone, and when it refuses. */
final class OverrideLabelsTest extends TestCase
{
    private OverrideLabelsExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new OverrideLabelsExtension();
    }

    /** @param array<string, mixed> $children a name, and the label it starts with */
    private static function form(array $children): FormView
    {
        $root = new FormView();

        foreach ($children as $name => $label) {
            $child = new FormView($root);
            $child->vars['label'] = $label;
            $root->children[$name] = $child;
        }

        return $root;
    }

    public function testItWritesOnTheNamedChild(): void
    {
        $form = self::form(['email' => 'Please enter your email address']);

        $this->extension->overrideLabels($form, ['email' => 'Email']);

        self::assertSame('Email', $form->children['email']->vars['label']);
    }

    /** A dependent field is absent until its condition is met: an unknown name is not a fault. */
    public function testAnUnknownNameIsIgnored(): void
    {
        $form = self::form(['email' => 'Email']);

        $this->extension->overrideLabels($form, ['siret' => 'SIRET', 'email' => 'Courriel']);

        self::assertSame('Courriel', $form->children['email']->vars['label']);
        self::assertArrayNotHasKey('siret', $form->children);
    }

    /** `false` is a form asking for no label at all, which is a decision and not a gap. */
    public function testALabelRefusedByTheFormIsLeftAlone(): void
    {
        $form = self::form(['token' => false]);

        $this->extension->overrideLabels($form, ['token' => 'Token']);

        self::assertFalse($form->children['token']->vars['label']);
    }

    public function testADottedPathReachesANestedChild(): void
    {
        $form = self::form(['password' => 'Password']);
        $first = new FormView($form->children['password']);
        $first->vars['label'] = 'First';
        $form->children['password']->children['first'] = $first;

        $this->extension->overrideLabels($form, ['password.first' => 'Mot de passe']);

        self::assertSame('Mot de passe', $first->vars['label']);
    }

    public function testItRefusesToWriteOnceFormStartHasRendered(): void
    {
        $form = self::form(['email' => 'Email']);
        $form->setMethodRendered();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/before form_start/');

        $this->extension->overrideLabels($form, ['email' => 'Courriel']);
    }

    public function testItRefusesOnASubViewOfAnAlreadyRenderedForm(): void
    {
        $form = self::form(['shipping' => 'Shipping']);
        $form->setMethodRendered();

        $this->expectException(\LogicException::class);

        $this->extension->overrideLabels($form->children['shipping'], ['city' => 'Ville']);
    }
}
