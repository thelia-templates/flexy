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

use FlexyBundle\Components\Organisms\NextButton\Base;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\ComponentAttributes;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Symfony\UX\TwigComponent\MountedComponent;
use Twig\Runtime\EscaperRuntime;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Cart\Service\CartGuard;
use Thelia\Domain\Checkout\Exception\MissingAddressException;
use Thelia\Domain\Checkout\Service\CheckoutProgressionService;
use Thelia\Domain\Checkout\Service\CheckoutStepTitleResolver;
use Thelia\Domain\Checkout\Service\CheckoutTunnelShape;
use Thelia\Domain\Checkout\Service\ConsentGuard;
use Thelia\Domain\Checkout\Service\Step\CheckoutStepProviderInterface;
use Thelia\Model\Cart;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Psr\Log\NullLogger;
use Thelia\Model\Currency;

/**
 * The check of a step a module declared is that module's code: the next button asks it once per render, although the
 * template reads the state of the button several times (the button, its link, the sentence under it).
 */
final class NextButtonModuleStepCheckTest extends KernelTestCase
{
    private ConnectionInterface $connection;

    protected function setUp(): void
    {
        $request = Request::create('/checkout/phone');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);
        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');

        // The cart of the session needs a default currency, which an empty test database lacks. Seeded inside a
        // transaction that is rolled back, so that the database is left as it was found.
        $this->connection = Propel::getConnection();
        $this->connection->beginTransaction();

        $currency = (new Currency())->setCode('EUR')->setSymbol('E')->setRate(1.0)->setByDefault(1);
        $currency->setLocale('en_US')->setName('Euro');
        $currency->save();
    }

    protected function tearDown(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheCheckOfAModuleStepRunsOncePerRender(): void
    {
        $provider = $this->refusingProvider();
        $mounted = new MountedComponent('Organisms:NextButton:Base', $this->button($provider), new ComponentAttributes(['id' => 'next-button'], self::getContainer()->get('twig')->getRuntime(EscaperRuntime::class)));

        $html = $this->renderer()->render($mounted);

        // Refused, so the template reads the state three times: the disabled flag, the link guard and the reason.
        self::assertStringContainsString('disabled', $html);
        self::assertSame(1, $provider->checks);
    }

    private function button(object $provider): Base
    {
        $container = self::getContainer();
        $progression = new CheckoutProgressionService(
            [$provider],
            $container->get(CheckoutTunnelShape::class),
            $container->get(CheckoutStepTitleResolver::class),
            new NullLogger(),
        );

        $button = new Base(
            $container->get(CartFacade::class),
            $progression,
            $container->get(ConsentGuard::class),
            $container->get(CartGuard::class),
            [$provider],
        );
        $button->mount('phone', '/checkout/confirmation');

        return $button;
    }

    private function refusingProvider(): CheckoutStepProviderInterface
    {
        return new class() implements CheckoutStepProviderInterface {
            public int $checks = 0;

            public function code(): string
            {
                return 'phone';
            }

            public function defaultPosition(): int
            {
                return 1;
            }

            public function isMandatory(): bool
            {
                return true;
            }

            public function isSkippedFor(Cart $cart): bool
            {
                return false;
            }

            public function check(Cart $cart): void
            {
                ++$this->checks;

                throw new MissingAddressException();
            }

            public function componentName(): ?string
            {
                return null;
            }
        };
    }

    private function renderer(): ComponentRendererInterface
    {
        return self::getContainer()->get('ux.twig_component.component_renderer');
    }
}
