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

namespace FlexyBundle\Tests\Component\Pricing;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Test\IntegrationTestCase;

/**
 * The toolkit shows each price component in the displays of the groups. The toolkit page itself
 * answers in debug only, so the stories are rendered here, as the toolkit renders them.
 */
final class PriceStoriesRenderTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $requestStack = static::getContainer()->get('request_stack');
        while (null !== $requestStack->pop()) {
        }

        $request = Request::create('https://localhost/toolkit');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        static::getContainer()->get('twig')->addGlobal('lang_code', 'en');
    }

    #[DataProvider('stories')]
    public function testTheStoryRenders(string $story): void
    {
        $html = static::getContainer()->get('twig')->createTemplate($this->storyOf($story))->render();

        self::assertStringContainsString('class="Story', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stories(): iterable
    {
        foreach (['Atoms/TaxMention', 'Organisms/ProductCard', 'Fields/RangeSlider', 'Organisms/Summary', 'Organisms/QuickOrderTable', 'Organisms/DeliveryMode', 'Organisms/CartButton'] as $story) {
            yield $story => [$story];
        }
    }

    public function testTheProductCardStoryDrawsBothDisplaysOfAPrice(): void
    {
        $page = new Crawler(static::getContainer()->get('twig')->createTemplate($this->storyOf('Organisms/ProductCard'))->render());

        self::assertGreaterThan(0, $page->filter('.ProductCard-price .TaxMention[data-tax-display="untaxed"]')->count());
    }

    public function testTheHomeAndPickupCardsOfTheDeliveryStepRenderWithoutADescription(): void
    {
        $page = new Crawler(static::getContainer()->get('twig')->createTemplate($this->storyOf('Organisms/DeliveryMode'))->render());

        self::assertCount(1, $page->filter('.DeliveryMode.HomeDelivery'));
        self::assertCount(1, $page->filter('.DeliveryMode.PickupDelivery'));
        self::assertCount(0, $page->filter('.HomeDelivery .DeliveryMode-description, .PickupDelivery .DeliveryMode-description'));
        self::assertStringContainsString('€6.00', $page->filter('.HomeDelivery')->text());
    }

    public function testTheOrderSummaryStoryReadsEachOrderTheWayItWasSold(): void
    {
        $page = new Crawler(static::getContainer()->get('twig')->createTemplate($this->storyOf('Organisms/Summary'))->render());
        $totals = $page->filter('.Summary-total .TaxMention')->each(static fn (Crawler $mention): string => (string) $mention->attr('data-tax-display'));
        $totalsToPay = $page->filter('.Summary-total')->each(static fn (Crawler $total): string => trim((string) $total->children()->first()->text()));

        // Four orders: taxed, untaxed, taxed with a discount, untaxed with a discount. The two untaxed
        // ones state their total without taxes, the taxes, then the total to pay; the taxed ones keep a
        // single total with its mention.
        self::assertSame(['taxed', 'taxed'], $totals);
        self::assertSame(['Total', 'Total incl. tax to pay', 'Total', 'Total incl. tax to pay'], $totalsToPay);
        self::assertCount(2, $page->filter('.Summary-line:contains("Total excl. tax")'));
    }

    /**
     * The story as the toolkit renders it. Its stories of real data name the product 1 of the demo
     * shop, which the test shop does not have: they are given a product that exists.
     */
    private function storyOf(string $story): string
    {
        $factory = $this->createFixtureFactory();
        $productId = $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['basePrice' => 25.0, 'baseQuantity' => 5])->getId();

        return str_replace('productId="1"', 'productId="'.$productId.'"', (string) file_get_contents(\dirname(__DIR__, 3).'/components/'.$story.'/toolkit.html.twig'));
    }
}
