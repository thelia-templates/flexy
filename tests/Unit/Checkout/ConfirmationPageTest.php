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

namespace FlexyBundle\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The confirmation page tells the buyer their payment went through only when it did, and lets
 * the payment module of the order say what is left to do (send a cheque, make a transfer).
 *
 * The page is rendered on its own: the frame it extends is replaced by its content block, and
 * the functions of the shop by stubs that record what the page asks of them.
 */
final class ConfirmationPageTest extends TestCase
{
    /** @var list<array{string, array<string, mixed>}> */
    private array $hookCalls = [];

    public function testAPaidOrderIsAnnouncedAsPaid(): void
    {
        $html = $this->render(['placed_order' => $this->order(paid: true)]);

        self::assertStringContainsString('Your payment has been confirmed', $html);
        self::assertStringNotContainsString('Your order has been placed', $html);
    }

    public function testAnOrderWaitingForItsMoneyIsNotAnnouncedAsPaid(): void
    {
        $html = $this->render(['placed_order' => $this->order(paid: false)]);

        self::assertStringContainsString('Your order has been placed', $html);
        self::assertStringNotContainsString('Your payment has been confirmed', $html);
    }

    public function testThePaymentModuleIsAskedAboutTheOrderPlaced(): void
    {
        $order = $this->order(paid: false);

        $this->render(['placed_order' => $order]);

        self::assertContains(['order-placed.additional-payment-info', ['order' => $order]], $this->hookCalls);
    }

    public function testWithoutAnOrderNothingIsClaimedAndNoModuleIsAsked(): void
    {
        $html = $this->render([]);

        self::assertStringNotContainsString('Your payment has been confirmed', $html);
        self::assertSame([], array_filter($this->hookCalls, static fn (array $call): bool => 'order-placed.additional-payment-info' === $call[0]));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(array $context): string
    {
        $this->hookCalls = [];

        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['checkout-base.html.twig' => '{% block checkoutContent %}{% endblock %}']),
            new FilesystemLoader(\dirname(__DIR__, 3)),
        ]), ['strict_variables' => true]);

        $twig->addFilter(new TwigFilter('trans', static fn (string $text): string => $text));
        $twig->addFunction(new TwigFunction('ux_icon', static fn (): string => '', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('path', static fn (): string => '/'));
        $twig->addFunction(new TwigFunction(
            'theme_hook',
            function (string $hookName, array $parameters = []): string {
                $this->hookCalls[] = [$hookName, $parameters];

                return '';
            },
            ['is_safe' => ['html']],
        ));

        return $twig->render('checkout-confirm.html.twig', $context);
    }

    private function order(bool $paid): object
    {
        return new class($paid) {
            public function __construct(private readonly bool $paid)
            {
            }

            public function isPaid(): bool
            {
                return $this->paid;
            }
        };
    }
}
