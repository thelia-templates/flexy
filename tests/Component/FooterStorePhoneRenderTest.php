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

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Model\ConfigQuery;

/**
 * The footer offers to call the shop only when the shop has a phone number: without one,
 * a "tel:" link would dial nothing.
 */
final class FooterStorePhoneRenderTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function missingPhoneNumbers(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'never set' => [null];
    }

    #[DataProvider('missingPhoneNumbers')]
    public function testNoPhoneLinkIsDrawnWithoutAPhoneNumber(?string $storePhone): void
    {
        $footer = $this->renderTheFooterWith($storePhone);

        self::assertCount(0, $footer->filter('a[href^="tel:"]'));
        self::assertCount(0, $footer->filter('.Footer-storePhone'));
    }

    public function testThePhoneLinkDialsTheNumberOfTheShop(): void
    {
        $footer = $this->renderTheFooterWith('01 23 (45) 67 89');

        $link = $footer->filter('.Footer-storePhone a[href^="tel:"]');
        self::assertCount(1, $link);
        self::assertSame('tel:0123456789', $link->attr('href'));
        self::assertSame('01 23 (45) 67 89', trim($link->text()));
    }

    /**
     * The configuration is read through a cache the kernel warms from the database: the
     * phone number is set in that cache only, so the database is left as it was. The
     * footer lists the contents of the information folder through the front API, which
     * reads the current request: one is pushed, as a page would have it.
     */
    private function renderTheFooterWith(?string $storePhone): Crawler
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push(Request::create('/'));

        $configs = ConfigQuery::findAllAsMap();
        unset($configs['store_phone']);

        if (null !== $storePhone) {
            $configs['store_phone'] = $storePhone;
        }

        ConfigQuery::initCache($configs);

        return new Crawler($renderer->createAndRender('Layouts:Footer:Base'));
    }
}
