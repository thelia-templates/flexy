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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\HttpFoundation\Session\Session;

/**
 * A product with no sale element has no price: its card shows none, rather than a price of
 * zero formatted from null.
 */
final class ProductCardWithoutPriceRenderTest extends KernelTestCase
{
    protected function setUp(): void
    {
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $request = Request::create('https://localhost/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');
    }

    public function testACardOfAProductWithoutASaleElementShowsNoPrice(): void
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        foreach (['Organisms:ProductCard:Base', 'Organisms:ProductCard:Search'] as $component) {
            $html = $renderer->createAndRender($component, ['product' => [
                'id' => 99999,
                'ref' => 'REF-99999',
                'visible' => true,
                'position' => 1,
                'virtual' => false,
                'publicUrl' => '/organic-cotton-t-shirt',
                'i18ns' => ['title' => 'Organic cotton t-shirt'],
                'ProductColor' => ['colors' => []],
                'productCategories' => [],
                'productSaleElements' => [],
            ]]);

            self::assertStringContainsString('Organic cotton t-shirt', $html, $component);
            self::assertStringNotContainsString('ProductCard-price', $html, $component);
        }
    }
}
