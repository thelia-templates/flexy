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

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\CMS\Publication\Preview\PublicationPreview;
use Thelia\Domain\CMS\Publication\Preview\PublicationPreviewLinkGenerator;
use Thelia\Model\Content;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;

/**
 * A page the core renders as a preview (its /preview route marks the request with `_publication_preview`) says so at
 * the top, and asks not to be indexed whatever the page itself asks. A page without the mark is left as it was.
 *
 * The theme needs no class of the core for it: most cases set the attribute themselves, so they run on a core that
 * has no preview at all; one follows a real signed link when the core has them. Boots the host kernel on the test
 * database `bin/test-prepare` creates.
 */
final class PublicationPreviewPageTest extends WebIntegrationTestCase
{
    private const string BANNER = '[data-testid="publication-preview-banner"]';

    private ?EventSubscriberInterface $previewMark = null;

    protected function tearDown(): void
    {
        if (null !== $this->previewMark) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->previewMark);
        }

        parent::tearDown();
    }

    public function testAPreviewShowsTheBannerAboveTheHeader(): void
    {
        $this->content('flexy-preview-banner.html');
        $this->markEveryRequestAsAPreview();

        $crawler = $this->page('/flexy-preview-banner.html');

        $banner = $crawler->filter(self::BANNER);
        self::assertCount(1, $banner);
        self::assertStringContainsString('Preview: this page is not online as shown.', $banner->text());
        self::assertCount(1, $banner->filter('.SnackBar.bg-warning-lightest[role="status"]'), 'the warning variant of the snack bar');
        self::assertCount(1, $crawler->filter('body > '.self::BANNER.' + header'), 'right above the header, outside the page body');
    }

    public function testAPreviewIsNeverIndexed(): void
    {
        $this->content('flexy-preview-robots.html');
        $this->markEveryRequestAsAPreview();

        self::assertSame(['noindex, nofollow'], $this->robots($this->page('/flexy-preview-robots.html')));
    }

    public function testThePreviewTagReplacesTheOneAPageSets(): void
    {
        $this->markEveryRequestAsAPreview();

        self::assertSame(['noindex, nofollow'], $this->robots($this->page('/checkout/cart')));
    }

    public function testAnOrdinaryPageHasNoBannerAndKeepsItsOwnTag(): void
    {
        $this->content('flexy-preview-ordinary.html');

        $content = $this->page('/flexy-preview-ordinary.html');
        self::assertCount(0, $content->filter(self::BANNER));
        self::assertSame([], $this->robots($content));

        $cart = $this->page('/checkout/cart');
        self::assertCount(0, $cart->filter(self::BANNER));
        self::assertSame(['noindex, follow'], $this->robots($cart));
    }

    public function testTheSignedLinkOfADraftShowsTheBanner(): void
    {
        if (!class_exists(PublicationPreviewLinkGenerator::class)) {
            self::markTestSkipped('The core published has no publication preview yet.');
        }

        $this->getService('limiter.publication_preview_per_client')->create('127.0.0.1')->reset();
        $draft = $this->content('flexy-preview-draft.html', visible: 0);
        $link = $this->getService(PublicationPreviewLinkGenerator::class)->generate(PublicationPreview::of('content', (int) $draft->getId()));

        $crawler = $this->page($link->url);

        self::assertStringContainsString('Preview banner body', $crawler->filter('main')->text());
        self::assertCount(1, $crawler->filter(self::BANNER));
        self::assertSame(['noindex, nofollow'], $this->robots($crawler));
    }

    public function testTheBannerIsTranslatedByTheTheme(): void
    {
        $translator = $this->getService('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        self::assertSame(
            'Prévisualisation : cette page n\'est pas en ligne telle quelle.',
            $translator->trans('Preview: this page is not online as shown.', [], 'messages', 'fr_FR'),
        );
    }

    /**
     * What the core's preview route does to the request it renders, without the route: the theme reads nothing else.
     */
    private function markEveryRequestAsAPreview(): void
    {
        $this->previewMark = new class implements EventSubscriberInterface {
            public static function getSubscribedEvents(): array
            {
                return [KernelEvents::REQUEST => ['mark', 64]];
            }

            public function mark(RequestEvent $event): void
            {
                if ($event->isMainRequest()) {
                    $event->getRequest()->attributes->set('_publication_preview', ['type' => 'content', 'id' => 0, 'locale' => null]);
                }
            }
        };

        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->previewMark);
    }

    private function page(string $url): Crawler
    {
        $crawler = $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $url);

        return $crawler;
    }

    /**
     * @return list<string>
     */
    private function robots(Crawler $crawler): array
    {
        return $crawler->filter('head meta[name="robots"]')->each(
            static fn (Crawler $meta): string => (string) $meta->attr('content'),
        );
    }

    /**
     * Built on the connection, not through the base class helper: that one pushes a synthetic request that becomes
     * the main one, and the page then renders without a session.
     */
    private function content(string $url, int $visible = 1): Content
    {
        $fixtures = new FixtureFactory($this->getPropelConnection());
        $content = $fixtures->content($fixtures->folder(0, ['visible' => 1]), ['visible' => $visible, 'title' => 'Preview content']);
        $content->setLocale('en_US')->setDescription('<p>Preview banner body</p>')->save($this->getPropelConnection());
        $content->setRewrittenUrl('en_US', $url);

        return $content;
    }
}
