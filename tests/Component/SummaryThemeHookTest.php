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

use FlexyBundle\DTO\DisplayedPrice;
use FlexyBundle\Service\TaxDisplay;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * A module paying part of the order another way than the payment module (a gift card, a store
 * credit) answers the `checkout.summary.bottom` theme hook: the checkout summary asks for it
 * under the total, with the figures it shows, and prints what the module answers.
 *
 * The handlers of a theme hook are collected when the container is compiled, so the template
 * is rendered here on its own, with a theme_hook() that records its calls.
 */
final class SummaryThemeHookTest extends TestCase
{
    public function testTheSummaryAsksTheModulesUnderTheTotalAndPrintsTheirAnswer(): void
    {
        $calls = [];

        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__, 2)), ['strict_variables' => true]);
        $twig->addExtension(new IntlExtension());
        $twig->addFilter(new TwigFilter('trans', static fn (string $message, array $parameters = []): string => strtr($message, $parameters)));
        $twig->addFunction(new TwigFunction('current_currency_code', static fn (): string => 'EUR'));
        $twig->addFunction(new TwigFunction('stimulus_controller', static fn (): string => ''));
        $twig->addFunction(new TwigFunction('ux_icon', static fn (): string => ''));
        $twig->addFunction(new TwigFunction(
            'theme_hook',
            static function (string $hookName, array $parameters = []) use (&$calls): string {
                $calls[] = [$hookName, $parameters];

                return '<div data-testid="module-summary-line">Left to pay</div>';
            },
            ['is_safe' => ['html']],
        ));

        $fifty = new DisplayedPrice(50.0, TaxDisplay::Taxed);
        $nothing = new DisplayedPrice(0.0, TaxDisplay::Taxed);

        $summary = [
            'display' => TaxDisplay::Taxed,
            'subtotal' => $fifty,
            'postage' => $nothing,
            'gift_wrapping_price' => $nothing,
            'discount_price' => $nothing,
            'total' => $fifty,
            'item_count' => 1,
            'raw_taxed_total_price' => 50.0,
            'total_taxed_price' => 50.0,
            'total_tax_amount' => 0.0,
            'delivery_module_id' => null,
            'delivery_date' => null,
            'coupons' => [],
            'discounts' => [],
            'vat_exemption_state' => null,
        ];

        $html = $twig->render('components/Organisms/Summary/Checkout.html.twig', [
            'attributes' => new class {
                public function defaults(string $attributes): string
                {
                    return $attributes;
                }
            },
            'this' => new class($summary) {
                public bool $showPromoCodeForm = false;

                /** @param array<string, mixed> $summary */
                public function __construct(public array $summary)
                {
                }

                public function hasGiftWrapping(): bool
                {
                    return false;
                }

                public function hasDiscount(): bool
                {
                    return false;
                }

                public function hasTax(): bool
                {
                    return false;
                }

                public function isVatExempted(): bool
                {
                    return false;
                }
            },
            'lang_code' => 'en',
        ]);

        self::assertSame([['checkout.summary.bottom', ['summary' => $summary]]], $calls);

        $answer = strpos($html, 'data-testid="module-summary-line"');
        self::assertNotFalse($answer, 'The answer of the module is printed.');
        self::assertGreaterThan(strpos($html, 'class="Summary-total"'), $answer, 'It comes under the total.');
    }
}
