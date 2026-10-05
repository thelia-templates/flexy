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

namespace FlexyBundle\Tests\Unit\View;

use FlexyBundle\View\ViewName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The view a controller-rendered page goes by, so that `theme_hook('layout.head.<view>')`
 * names it the way the core names the pages it routes itself.
 */
final class ViewNameTest extends TestCase
{
    #[DataProvider('templates')]
    public function testTheViewIsTheTemplateWithoutItsExtension(string $templateName): void
    {
        self::assertSame('checkout-cart', ViewName::fromTemplate($templateName));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function templates(): iterable
    {
        yield 'bare name, as the theme controllers pass it' => ['checkout-cart'];
        yield 'twig template' => ['checkout-cart.html.twig'];
        yield 'short twig extension' => ['checkout-cart.twig'];
        yield 'surrounding spaces' => [' checkout-cart.html.twig '];
    }

    public function testAnEmptyTemplateNameHasNoView(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ViewName::fromTemplate('.html.twig');
    }
}
