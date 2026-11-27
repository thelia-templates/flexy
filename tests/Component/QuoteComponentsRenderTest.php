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
use Thelia\Core\HttpFoundation\Session\Session;

/**
 * The pieces of the quote pages, drawn from static data shaped like what the API hands over: the card of
 * the list, the pill of the state and the table of the lines with the catalogue price beside the negotiated one.
 */
final class QuoteComponentsRenderTest extends KernelTestCase
{
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        // The URL helpers of the Twig engine read the request in progress.
        $this->requestStack = self::getContainer()->get('request_stack');
        $this->requestStack->push(Request::create('/account/quotes'));
        // Assigned by the page parser only: a component rendered on its own has none.
        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');
    }

    protected function tearDown(): void
    {
        $this->requestStack->pop();

        parent::tearDown();
    }

    public function testTheCardLinksTheQuoteAndShowsItsState(): void
    {
        $html = $this->render('Organisms:QuoteCard:Base', ['quote' => $this->quote()]);

        self::assertStringContainsString('href="/account/quote/12"', $html);
        self::assertStringContainsString('Quote QUO00000012', $html);
        self::assertStringContainsString('data-state="quoted"', $html);
        self::assertStringContainsString('data-testid="quote-valid-until"', $html);
    }

    public function testTheCardShowsATotalOnlyWhenTheCollectionCarriesOne(): void
    {
        self::assertStringNotContainsString('data-testid="quote-total"', $this->render('Organisms:QuoteCard:Base', ['quote' => $this->quote()]));
        self::assertStringContainsString('data-testid="quote-total"', $this->render('Organisms:QuoteCard:Base', ['quote' => ['totalTaxed' => '1620.000000'] + $this->quote()]));
    }

    public function testTheCardCallsTheTotalOfARequestTheShopHasNotAnsweredTheAmountAskedFor(): void
    {
        $asked = $this->render('Organisms:QuoteCard:Base', ['quote' => ['state' => 'requested', 'totalTaxed' => '1440.000000'] + $this->quote()]);
        $answered = $this->render('Organisms:QuoteCard:Base', ['quote' => ['totalTaxed' => '1620.000000'] + $this->quote()]);

        self::assertStringContainsString('Requested amount', $asked);
        self::assertStringContainsString('data-testid="quote-total-label"', $asked);
        self::assertStringNotContainsString('Requested amount', $answered);
        self::assertStringContainsString('data-testid="quote-total"', $answered);
    }

    public function testTheWayOutOfTheCartOfAQuoteDrawsNothingForAnOrdinaryCart(): void
    {
        // The cart in hand is read from the session of the request.
        $this->requestStack->getMainRequest()?->setSession(self::getContainer()->get(Session::class));

        $html = $this->render('Organisms:QuoteCartExit:Base', []);

        self::assertSame('', trim($html));
    }

    public function testAReferenceIsEscapedOnceAndOnlyOnce(): void
    {
        $html = $this->render('Organisms:QuoteCard:Base', ['quote' => ['ref' => 'A&B<i>'] + $this->quote()]);

        self::assertStringContainsString('Quote A&amp;B&lt;i&gt;', $html);
        self::assertStringNotContainsString('&amp;amp;', $html);
        self::assertStringNotContainsString('<i>', $html);
    }

    public function testEveryStateHasItsOwnPill(): void
    {
        foreach (['requested', 'quoted', 'countered', 'accepted', 'rejected', 'expired', 'ordered'] as $state) {
            self::assertStringContainsString('data-state="'.$state.'"', $this->render('Molecules:QuoteState:Base', ['state' => $state]), $state);
        }
    }

    public function testTheLinesSetTheNegotiatedPriceBesideTheCatalogueOneOnceTheShopHasAnswered(): void
    {
        $html = $this->render('Organisms:QuoteLines:Base', ['revision' => $this->revision('admin'), 'currencyCode' => 'EUR']);

        self::assertStringContainsString('Catalogue price', $html);
        self::assertStringContainsString('Negotiated price', $html);
        self::assertSame(2, substr_count($html, 'data-testid="quote-line-price"'));
        self::assertStringContainsString('line-through', $html, 'The catalogue price is struck where the shop gives another one.');
    }

    public function testWhatTheBuyerAskedForHasTheCatalogueColumnAlone(): void
    {
        $html = $this->render('Organisms:QuoteLines:Base', ['revision' => $this->revision('customer'), 'currencyCode' => 'EUR']);

        self::assertStringContainsString('Catalogue price', $html);
        self::assertStringNotContainsString('Negotiated price', $html);
        self::assertStringNotContainsString('line-through', $html);
    }

    public function testATitleTypedByAPersonIsEscaped(): void
    {
        $revision = $this->revision('admin');
        $revision['items'][0]['title'] = '<script>alert(1)</script>';

        $html = $this->render('Organisms:QuoteLines:Base', ['revision' => $revision, 'currencyCode' => 'EUR']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function quote(): array
    {
        return [
            'id' => 12,
            'ref' => 'QUO00000012',
            'state' => 'quoted',
            'currencyCode' => 'EUR',
            'createdAt' => '2026-11-02',
            'validUntil' => '2026-12-02',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function revision(string $author): array
    {
        return [
            'number' => 2,
            'author' => $author,
            'discount' => '0.000000',
            'postage' => '0.000000',
            'totalUntaxed' => '1000.000000',
            'totalTaxed' => '1200.000000',
            'items' => [
                ['id' => 1, 'title' => 'Oak table', 'productRef' => 'TABLE', 'quantity' => 2, 'catalogPrice' => '100.000000', 'price' => '90.000000'],
                ['id' => 2, 'title' => 'Chair', 'productRef' => 'CHAIR', 'quantity' => 4, 'catalogPrice' => '50.000000', 'price' => 'admin' === $author ? '45.000000' : '50.000000'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(string $component, array $props): string
    {
        return self::getContainer()->get('ux.twig_component.component_renderer')->createAndRender($component, $props);
    }
}
