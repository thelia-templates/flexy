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

namespace FlexyBundle\Tests\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\ComponentRendererInterface;

/** When the error summary appears, and what it puts in it. */
final class FormErrorsRenderTest extends KernelTestCase
{
    private function render(array $props): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender('Molecules:FormErrors:Base', $props);
    }

    private static function entry(?string $label, array $messages, ?string $target, bool $hidden): array
    {
        return ['label' => $label, 'messages' => $messages, 'target' => $target, 'hidden' => $hidden];
    }

    public function testNothingIsRenderedWithoutAnError(): void
    {
        self::assertSame('', trim($this->render([])));
    }

    public function testASingleVisibleFieldGetsNoSummary(): void
    {
        $html = $this->render([
            'sampleEntries' => [self::entry('Email', ['This value should not be blank.'], 'email', false)],
        ]);

        self::assertStringContainsString('FormErrors', $html, 'the host still carries the focus controller');
        self::assertStringNotContainsString('FormErrors-summary', $html);
    }

    public function testTwoFieldsGetASummaryWithOneAnchorEach(): void
    {
        $html = $this->render([
            'sampleEntries' => [
                self::entry('Email', ['Blank.'], 'email', false),
                self::entry('Password', ['Blank.'], 'password', false),
            ],
        ]);

        self::assertStringContainsString('FormErrors-summary', $html);
        self::assertSame(2, substr_count($html, '<li>'));
        self::assertStringContainsString('href="#email"', $html);
        self::assertStringContainsString('href="#password"', $html);
    }

    /** A hidden field has nowhere else to be read, so one error is enough to open the summary. */
    public function testAHiddenFieldAloneGetsASummaryWithoutAnchor(): void
    {
        $html = $this->render([
            'sampleEntries' => [self::entry(null, ['The captcha is invalid.'], null, true)],
        ]);

        self::assertStringContainsString('FormErrors-summary', $html);
        self::assertStringContainsString('The captcha is invalid.', $html);
        self::assertStringNotContainsString('<a href="#', $html);
    }

    /** The only rendering of a form-level error in this theme: the form_errors block is empty. */
    public function testRootErrorsAloneAreShown(): void
    {
        $html = $this->render(['sampleRootErrors' => ['Wrong email or password.']]);

        self::assertStringContainsString('FormErrors-summary', $html);
        self::assertStringContainsString('Wrong email or password.', $html);
    }

    /** The count counts fields, not messages. */
    public function testAFieldKeepsOneEntryForAllItsMessages(): void
    {
        $html = $this->render([
            'sampleEntries' => [
                self::entry('City', ['Blank.', 'Letters only.'], 'city', false),
                self::entry('Zip code', ['Blank.'], 'zipcode', false),
            ],
        ]);

        self::assertSame(2, substr_count($html, '<li>'));
        self::assertSame(1, substr_count($html, 'href="#city"'));
        self::assertStringContainsString('Blank. Letters only.', $html);
    }
}
