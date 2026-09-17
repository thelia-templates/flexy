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

/** The dismiss button a tag grows when it is given an action. */
final class TagRenderTest extends KernelTestCase
{
    private function render(array $props): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender('Molecules:Tag:Base', $props);
    }

    private function closeButton(string $html): string
    {
        preg_match('/<button[^>]*Tag__iconClose[^>]*>/', $html, $button);

        self::assertNotEmpty($button, 'the tag renders its dismiss button');

        return $button[0];
    }

    /** A listing's active filters sit in a form, and the HTML default for a button is submit. */
    public function testTheDismissButtonDeclaresItIsNotASubmitButton(): void
    {
        $html = $this->render(['customText' => 'Blue', 'data-action' => 'listing#removeFilter']);

        self::assertStringContainsString('type="button"', $this->closeButton($html));
    }

    /** The button holds nothing but an icon. */
    public function testTheDismissButtonCarriesAnAccessibleName(): void
    {
        $html = $this->render(['customText' => 'Blue', 'data-action' => 'listing#removeFilter']);

        self::assertMatchesRegularExpression('/aria-label="[^"]+"/', $this->closeButton($html));
    }

    public function testATagWithoutAnActionRendersNoDismissButton(): void
    {
        $html = $this->render(['customText' => 'Blue']);

        self::assertStringNotContainsString('Tag__iconClose', $html);
    }
}
