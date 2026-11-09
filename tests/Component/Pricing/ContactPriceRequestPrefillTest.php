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

use Thelia\Test\WebIntegrationTestCase;

/**
 * "Request a price" leads to the contact page with the references of the item: the message is
 * started for the buyer, with the reference and nothing else (no amount), escaped.
 */
final class ContactPriceRequestPrefillTest extends WebIntegrationTestCase
{
    public function testTheMessageIsStartedWithTheReferenceOfTheItem(): void
    {
        $this->assertPageRenders('/contact?product_ref=SOFA&pse_ref=SOFA-RED');

        self::assertSame('Price request for SOFA-RED', trim($this->client->getCrawler()->filter('textarea')->text()));
    }

    public function testTheReferenceOfTheProductIsUsedWhenTheSaleElementHasNone(): void
    {
        $this->assertPageRenders('/contact?product_ref=SOFA');

        self::assertSame('Price request for SOFA', trim($this->client->getCrawler()->filter('textarea')->text()));
    }

    public function testTheContactPageWithoutReferenceHasAnEmptyMessage(): void
    {
        $this->assertPageRenders('/contact');

        self::assertSame('', trim($this->client->getCrawler()->filter('textarea')->text()));
    }

    public function testAReferenceIsEscapedAndNothingElseOfTheQueryIsPrinted(): void
    {
        $this->assertPageRenders('/contact?pse_ref='.rawurlencode('"></textarea><script>alert(1)</script>').'&price=99.99');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('99.99', $this->client->getCrawler()->filter('textarea')->text(), 'Only the reference reaches the message.');
    }

    public function testAnArrayInTheQueryDoesNotBreakThePage(): void
    {
        $this->assertPageRenders('/contact?pse_ref[]=a&product_ref[x]=b');

        self::assertSame('', trim($this->client->getCrawler()->filter('textarea')->text()));
    }

    public function testAMessageAlreadyTypedIsNeverReplaced(): void
    {
        $this->client->request('POST', '/contact?pse_ref=SOFA-RED', [
            'thelia_contact' => ['name' => 'A', 'email' => 'not-an-email', 'subject' => 'S', 'message' => 'My own words'],
        ]);

        self::assertStringContainsString('My own words', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Price request for SOFA-RED', (string) $this->client->getResponse()->getContent());
    }
}
