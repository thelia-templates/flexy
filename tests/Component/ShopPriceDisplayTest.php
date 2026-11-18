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

use FlexyBundle\Service\ShopPriceDisplay;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\ConfigQuery;

/**
 * A shop that shows its prices to nobody (`price_display_mode` = `on_request`, what a showcase
 * site sets) offers no cart and no currency in the header: the core leaves the prices out of the
 * front API and refuses the cart, and the components print what it publishes. Every case is
 * played with the prices shown too, so that what it leaves out is known to be there otherwise.
 */
final class ShopPriceDisplayTest extends KernelTestCase
{
    private ?string $storedMode = null;

    protected function setUp(): void
    {
        // The kernel first: it is what connects Propel.
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $this->storedMode = ConfigQuery::create()->findOneByName(ShopPriceDisplay::CONFIG_KEY)?->getValue();
        $request = Request::create('https://localhost/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');
    }

    protected function tearDown(): void
    {
        if (null === $this->storedMode) {
            ConfigQuery::create()->filterByName(ShopPriceDisplay::CONFIG_KEY)->delete();
        } else {
            ConfigQuery::write(ShopPriceDisplay::CONFIG_KEY, $this->storedMode);
        }

        ConfigQuery::resetCache();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function modes(): iterable
    {
        yield 'prices shown' => ['visible', true];
        yield 'prices on request' => ['on_request', false];
        yield 'a value the theme does not know' => ['typo', true];
    }

    #[DataProvider('modes')]
    public function testTheHeaderOffersTheCartOnlyWithThePrices(string $mode, bool $shown): void
    {
        $this->setMode($mode);

        $html = $this->render('Organisms:HeaderNav:Base');

        self::assertSame($shown, str_contains($html, 'Organisms:CartButton:Base'), 'cart button');
        self::assertSame($shown, str_contains($html, 'CurrencySelect'), 'currency selector');
        self::assertStringContainsString('LangSelect', $html, 'the rest of the header stays');
    }

    private function setMode(string $mode): void
    {
        ConfigQuery::write(ShopPriceDisplay::CONFIG_KEY, $mode);
        ConfigQuery::resetCache();
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(string $component, array $props = []): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender($component, $props);
    }
}
