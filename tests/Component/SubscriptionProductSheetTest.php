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

use FlexyBundle\Service\Subscription\SubscriptionLineView;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Thelia\Domain\Subscription\SubscriptionFactory;
use Thelia\Model\Currency;
use Thelia\Model\Product;
use Thelia\Model\SubscriptionPlan;
use Thelia\Model\SubscriptionPlanPrice;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;
use Twig\Environment;

/**
 * The sheet of a product sold by subscription offers the plans next to the one-time purchase, with the price of an
 * installment taxes included when the page shows them so; a visitor who is not signed in sees them with an invitation
 * to sign in. A product with no plan keeps the sheet it had. The cart line of a plan says when the first installment is.
 *
 * Skipped on a core published without the subscriptions.
 */
final class SubscriptionProductSheetTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('Thelia\\Domain\\Subscription\\SubscriptionStateMachine')) {
            self::markTestSkipped('The core published does not carry the subscriptions yet.');
        }
    }

    public function testThePlansAreOfferedNextToTheOneTimePurchaseWithTheTaxedPriceOfAnInstallment(): void
    {
        $factory = $this->createFixtureFactory();
        $product = $this->product($factory);
        $this->plan($product, '10.000000', ['TrialDays' => 14, 'CommitmentCount' => 3]);

        $crawler = $this->page($product);

        self::assertCount(1, $crawler->filter('[data-testid="subscription-plans"]'));
        self::assertCount(1, $crawler->filter('[data-testid="subscription-plan"]'));
        $text = $crawler->filter('[data-testid="subscription-plan"]')->text();
        self::assertStringContainsString('Monthly box', $text);
        self::assertStringContainsString('every month', $text);
        self::assertStringContainsString('14 days free trial', $text);
        self::assertStringContainsString('Commitment of 3 installments', $text);
        self::assertMatchesRegularExpression('/€\s?1[0-9]\.[0-9]{2}/', $text, 'The price of one installment, in the currency of the visitor.');
        self::assertStringContainsString('One-time purchase', $crawler->filter('[data-testid="subscription-plans"]')->text());
    }

    public function testAVisitorWhoIsNotSignedInSeesThePlansDisabledWithAnInvitationToSignIn(): void
    {
        $factory = $this->createFixtureFactory();
        $product = $this->product($factory);
        $this->plan($product, '10.000000');

        $crawler = $this->page($product);

        self::assertCount(1, $crawler->filter('[data-testid="subscription-plan"] input[disabled]'));
        self::assertCount(1, $crawler->filter('[data-testid="subscription-sign-in"] a[href*="/customer/login"]'));
    }

    public function testAProductWithoutAPlanKeepsItsSheet(): void
    {
        $factory = $this->createFixtureFactory();

        self::assertCount(0, $this->page($this->product($factory))->filter('[data-testid="subscription-plans"]'));
    }

    public function testAHiddenPlanIsNotOffered(): void
    {
        $factory = $this->createFixtureFactory();
        $product = $this->product($factory);
        $this->plan($product, '10.000000', ['Visible' => 0]);

        self::assertCount(0, $this->page($product)->filter('[data-testid="subscription-plans"]'));
    }

    /**
     * The date told in the cart and at payment is the one billing will write, from the same
     * calendar and the same clock: a trial of 30 days taken on 31 January 2028 ends on 1 March.
     */
    public function testTheFirstInstallmentOfATrialIsTheDateBillingWillWrite(): void
    {
        $factory = $this->createFixtureFactory();
        $plan = $this->plan($this->product($factory), '10.000000', ['TrialDays' => 30]);
        $clockOfTheShop = Clock::get();
        Clock::set(new MockClock('2028-01-31 23:30:00', date_default_timezone_get()));

        try {
            $lineView = self::getContainer()->get(SubscriptionLineView::class);

            self::assertSame('2028-03-01', $lineView->firstInstallmentOn($plan));
            self::assertSame(
                self::getContainer()->get(SubscriptionFactory::class)->firstBillingDateOf($plan)->format('Y-m-d'),
                $lineView->firstInstallmentOn($plan),
            );
        } finally {
            Clock::set($clockOfTheShop);
        }
    }

    public function testAPlanWithoutATrialTellsNoFirstInstallment(): void
    {
        $factory = $this->createFixtureFactory();
        $plan = $this->plan($this->product($factory), '10.000000');

        self::assertNull(self::getContainer()->get(SubscriptionLineView::class)->firstInstallmentOn($plan), 'The order paid is the first installment.');
    }

    public function testTheLineViewIsServedByTheContainer(): void
    {
        self::assertInstanceOf(SubscriptionLineView::class, self::getContainer()->get(SubscriptionLineView::class));
    }

    private function product(FixtureFactory $factory): Product
    {
        return $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['baseQuantity' => 5, 'basePrice' => 10.0]);
    }

    /**
     * @param array<string, mixed> $values by phpName
     */
    private function plan(Product $product, string $price, array $values = []): SubscriptionPlan
    {
        $plan = (new SubscriptionPlan())
            ->setProductId($product->getId())
            ->setPeriodUnit('month')
            ->setPeriodCount(1)
            ->setTrialDays(0)
            ->setPausable(0)
            ->setVisible(1)
            ->setPosition(1);

        foreach ($values as $name => $value) {
            $plan->setByName($name, $value);
        }

        $plan->setLocale('en_US')->setTitle('Monthly box');
        $plan->save();
        (new SubscriptionPlanPrice())
            ->setSubscriptionPlanId($plan->getId())
            ->setCurrencyId(Currency::getDefaultCurrency()->getId())
            ->setPrice($price)
            ->setFromDefaultCurrency(0)
            ->save();

        return $plan;
    }

    private function page(Product $product): Crawler
    {
        $twig = $this->getService('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $twig->addGlobal('lang_code', 'en');
        $html = $twig->createTemplate(
            '{{ component("Layouts:ProductDetails:Base", {product: {id: id, i18ns: {title: "Calvados"}}}) }}',
            'product-sheet.html.twig',
        )->render(['id' => $product->getId()]);

        return new Crawler($html);
    }
}
