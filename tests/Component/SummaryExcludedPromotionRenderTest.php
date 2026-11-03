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

use FlexyBundle\Service\ExcludedPromotionsReader;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\ComponentFactory;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Api\Service\DataAccess\AttributeAccessService;
use Thelia\Core\HttpFoundation\Session\Session;

/**
 * The summary tells the buyer why a code they typed is not in the cart: another promotion was kept instead.
 * The core publishes the codes left out as the `excluded_promotions` attribute; the theme reads it with a
 * strict shape check, prints the merchant titles escaped, and renders nothing for a core that does not know it.
 */
final class SummaryExcludedPromotionRenderTest extends KernelTestCase
{
    private const string NAME = 'Organisms:Summary:Checkout';

    private mixed $excluded = '';

    protected function setUp(): void
    {
        // The core's attribute service is already built when the kernel boots and cannot be replaced after: the
        // theme's reader of the attribute is rebuilt over a simulated one, the rest of the summary reads the
        // real (empty) cart.
        $attributes = $this->createStub(AttributeAccessService::class);
        $attributes->method('attributeCart')->willReturnCallback(
            fn (string $name): mixed => 'excluded_promotions' === $name ? $this->excluded : null,
        );
        self::getContainer()->set(ExcludedPromotionsReader::class, new ExcludedPromotionsReader($attributes));

        $request = Request::create('/checkout/cart');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);
        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');
    }

    public function testTheCodeLeftOutIsExplained(): void
    {
        $html = $this->render([['code' => 'SUMMER', 'title' => 'Summer -10%', 'winner_title' => 'Black Friday -20%']]);

        self::assertStringContainsString('data-summary-excluded-promotion="SUMMER"', $html);
        self::assertStringContainsString('The code SUMMER (Summer -10%) cannot be combined with the &quot;Black Friday -20%&quot; promotion, which was applied.', $html);
        self::assertStringContainsString('role="status"', $html);
    }

    public function testTheMerchantTitlesAreEscaped(): void
    {
        $html = $this->render([['code' => 'X', 'title' => '<script>alert(1)</script>', 'winner_title' => '<b onmouseover=1>W</b>']]);

        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringNotContainsString('<b onmouseover', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testMalformedEntriesAreIgnoredAndTheWellFormedOneKept(): void
    {
        $html = $this->render([
            'not an array',
            ['code' => 'NOTITLE', 'title' => '', 'winner_title' => 'W'],
            ['code' => ['A'], 'title' => 'T', 'winner_title' => 'W'],
            ['code' => 'NOWINNER', 'title' => 'T'],
            ['code' => 'BLANK', 'title' => '   ', 'winner_title' => 'W'],
            ['code' => 'GOOD', 'title' => 'T', 'winner_title' => 'W'],
        ]);

        self::assertSame(1, substr_count($html, 'data-summary-excluded-promotion='));
        self::assertStringContainsString('data-summary-excluded-promotion="GOOD"', $html);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function noUsableAttribute(): iterable
    {
        yield 'core without the attribute (empty string)' => [''];
        yield 'null' => [null];
        yield 'empty list' => [[]];
        yield 'a scalar' => ['SUMMER'];
    }

    #[DataProvider('noUsableAttribute')]
    public function testNothingIsRenderedWithoutAUsableAttribute(mixed $attribute): void
    {
        self::assertStringNotContainsString('data-summary-excluded-promotion', $this->render($attribute));
    }

    private function render(mixed $excluded): string
    {
        $this->excluded = $excluded;

        /** @var ComponentFactory $factory */
        $factory = self::getContainer()->get('ux.twig_component.component_factory');
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->render($factory->create(self::NAME, ['id' => 'summary']));
    }
}
