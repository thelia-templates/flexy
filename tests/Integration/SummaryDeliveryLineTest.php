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

namespace FlexyBundle\Tests\Integration;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\CartQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The checkout summary announces a free delivery only for a carrier that was chosen and costs nothing: a cart without a
 * carrier has no postage to show yet, and says nothing about it. Runs on an installed shop (bin/test-prepare).
 */
final class SummaryDeliveryLineTest extends IntegrationTestCase
{
    public function testNoCarrierYetShowsNoDeliveryLine(): void
    {
        $html = $this->summaryOf(null, '0.000000');

        self::assertStringNotContainsString('Free delivery', $html);
        self::assertStringNotContainsString('<div>Delivery</div>', $html);
    }

    public function testAChosenCarrierThatCostsNothingIsAFreeDelivery(): void
    {
        self::assertStringContainsString('Free delivery', $this->summaryOf($this->someModuleId(), '0.000000'));
    }

    public function testAChosenCarrierShowsItsPrice(): void
    {
        $html = $this->summaryOf($this->someModuleId(), '6.900000');

        self::assertStringNotContainsString('Free delivery', $html);
        self::assertStringContainsString('6.90', $html);
    }

    private function someModuleId(): int
    {
        return (int) ModuleQuery::create()->findOne()?->getId();
    }

    private function summaryOf(?int $deliveryModuleId, string $postage): string
    {
        $factory = $this->createFixtureFactory();
        $cart = $factory->cart(null, ['currency' => $factory->currency()]);
        $cart->setDeliveryModuleId($deliveryModuleId)->setPostage($postage)->save();

        $stack = static::getContainer()->get('request_stack');
        \assert($stack instanceof RequestStack);
        $session = $stack->getCurrentRequest()?->getSession();
        \assert($session instanceof Session);
        $session->setSessionCart(CartQuery::create()->findPk($cart->getId()));

        // Part of the context the Thelia parser hands a page template, which a component rendered alone does not get.
        static::getContainer()->get('twig')->addGlobal('lang_code', 'en');

        $renderer = static::getContainer()->get('ux.twig_component.component_renderer');
        \assert($renderer instanceof ComponentRendererInterface);

        return $renderer->createAndRender('Organisms:Summary:Checkout', ['id' => 'summary']);
    }
}
