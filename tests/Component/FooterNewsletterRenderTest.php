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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Model\ConfigQuery;

/**
 * The footer offers the newsletter column only when the page asks for it and the shop takes
 * sign-ups: switched off, neither the column nor its modifier class (which draws a third
 * column) is rendered.
 */
final class FooterNewsletterRenderTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testTheColumnIsDrawnWhenTheShopTakesSignUps(): void
    {
        $footer = $this->renderTheFooter(withNewsletter: true, newsletterEnabled: '1');

        self::assertCount(1, $footer->filter('.Footer-newsletter'));
        self::assertCount(1, $footer->filter('footer.Footer--withNewsletter'));
    }

    public function testTheColumnIsDrawnWhenTheShopNeverSetTheVariable(): void
    {
        $footer = $this->renderTheFooter(withNewsletter: true, newsletterEnabled: null);

        self::assertCount(1, $footer->filter('.Footer-newsletter'));
    }

    public function testNeitherTheColumnNorTheModifierIsDrawnWhenTheShopTurnsTheNewsletterOff(): void
    {
        $footer = $this->renderTheFooter(withNewsletter: true, newsletterEnabled: '0');

        self::assertCount(0, $footer->filter('.Footer-newsletter'));
        self::assertCount(0, $footer->filter('footer.Footer--withNewsletter'));
    }

    public function testTheColumnIsNotDrawnWhenThePageDoesNotAskForIt(): void
    {
        $footer = $this->renderTheFooter(withNewsletter: false, newsletterEnabled: '1');

        self::assertCount(0, $footer->filter('.Footer-newsletter'));
        self::assertCount(0, $footer->filter('footer.Footer--withNewsletter'));
    }

    /**
     * The configuration is read through a cache the kernel warms from the database: the
     * variable is set in that cache only, so the database is left as it was.
     */
    private function renderTheFooter(bool $withNewsletter, ?string $newsletterEnabled): Crawler
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push(Request::create('/'));

        $configs = ConfigQuery::findAllAsMap();
        unset($configs['newsletter_enabled']);

        if (null !== $newsletterEnabled) {
            $configs['newsletter_enabled'] = $newsletterEnabled;
        }

        ConfigQuery::initCache($configs);

        return new Crawler($renderer->createAndRender('Layouts:Footer:Base', ['withNewsletter' => $withNewsletter]));
    }
}
