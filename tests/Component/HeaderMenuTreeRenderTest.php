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

use FlexyBundle\Components\Layouts\Header\Base as Header;
use FlexyBundle\Service\NavigationTree;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\Content\Slot\ContentSlotLink;
use Thelia\Core\Content\Slot\ContentSlotResolverInterface;
use Thelia\Core\Content\Slot\ContentSlots;
use Thelia\Core\Content\Slot\ContentSlotService;
use Thelia\Domain\Localization\Service\LangService;

/**
 * A header entry that brings its own children (a CMS menu) opens the same mega-menu as a
 * folder: a child with children of its own is a column, the others are listed side by side.
 */
final class HeaderMenuTreeRenderTest extends KernelTestCase
{
    public function testAChildWithChildrenIsAColumnAndTheOthersAreListedLinks(): void
    {
        $crawler = $this->render(new ContentSlotLink(label: 'Agency', url: 'https://shop.example/agency', source: 'cms_page', sourceId: 12, children: [
            new ContentSlotLink(label: 'Team', url: 'https://shop.example/agency/team', source: 'cms_page', sourceId: 14, children: [
                new ContentSlotLink(label: 'Founders', url: 'https://shop.example/agency/team/founders', source: 'cms_page', sourceId: 16),
            ]),
            new ContentSlotLink(label: 'Careers', url: 'https://shop.example/agency/careers', source: 'cms_page', sourceId: 15),
        ]));

        self::assertSame('Agency', trim($crawler->filter('button.HeaderMenuItem-link')->text()));

        $column = $crawler->filter('.Submenu-column:not(.Submenu-column--links)');
        self::assertCount(1, $column, 'The child with children of its own makes one column.');
        self::assertSame('https://shop.example/agency/team', $column->filter('a.Submenu-heading')->attr('href'));
        self::assertSame('https://shop.example/agency/team/founders', $column->filter('.Submenu-sub a.Submenu-link')->attr('href'));

        $links = $crawler->filter('.Submenu-column--links a.Submenu-link');
        self::assertSame(['Careers'], $links->each(static fn (Crawler $link): string => trim($link->text())));
    }

    /** No address is a heading, not a link to nowhere; an outside address opens apart. */
    public function testAChildWithoutAnAddressIsTextAndANewWindowLinkSaysSo(): void
    {
        $crawler = $this->render(new ContentSlotLink(label: 'Agency', url: null, source: 'cms_page', sourceId: 12, children: [
            new ContentSlotLink(label: 'Join us', url: null, source: 'cms_page', sourceId: 15),
            new ContentSlotLink(label: 'Blog', url: 'https://blog.example', source: 'url', opensInNewWindow: true),
        ]));

        self::assertSame('Join us', trim($crawler->filter('.Submenu-column--links span.Submenu-link')->text()));
        self::assertSame('_blank', $crawler->filter('.Submenu-column--links a.Submenu-link')->attr('target'));
        self::assertSame('noopener', $crawler->filter('.Submenu-column--links a.Submenu-link')->attr('rel'));
        self::assertCount(0, $crawler->filter('a[href=""]'), 'No link may point nowhere.');
    }

    public function testTwoTreesGetTwoMenuKeys(): void
    {
        $first = $this->render(new ContentSlotLink(label: 'A', url: null, source: 'url', children: [new ContentSlotLink(label: 'A1', url: '/a1', source: 'url')]), 3);
        $second = $this->render(new ContentSlotLink(label: 'B', url: null, source: 'url', children: [new ContentSlotLink(label: 'B1', url: '/b1', source: 'url')]), 4);

        self::assertNotSame($first->filter('.Submenu')->attr('data-menu-sub'), $second->filter('.Submenu')->attr('data-menu-sub'));
    }

    /**
     * The header sorts each link of its slot by what it carries: children make a tree, a
     * folder without children its own mega-menu, anything else a plain link.
     */
    public function testTheHeaderSortsTheLinksOfItsSlotByWhatTheyCarry(): void
    {
        $team = new ContentSlotLink(label: 'Team', url: 'https://shop.example/agency/team', source: 'cms_page', sourceId: 14);
        $agency = new ContentSlotLink(label: 'Agency', url: 'https://shop.example/agency', source: 'cms_page', sourceId: 12, children: [$team]);
        $folderWithChildren = new ContentSlotLink(label: 'Guides', url: '/guides', source: 'folder', sourceId: 7, children: [$team]);
        $folder = new ContentSlotLink(label: 'Blog', url: '/blog', source: 'folder', sourceId: 2);
        $outside = new ContentSlotLink(label: 'Shop blog', url: 'https://blog.example', source: 'url', opensInNewWindow: true);

        $resolver = new class([$agency, $folderWithChildren, $folder, $outside]) implements ContentSlotResolverInterface {
            /** @param list<ContentSlotLink> $links */
            public function __construct(private readonly array $links)
            {
            }

            public function resolve(string $slot, string $locale): ?array
            {
                return ContentSlots::HEADER === $slot ? $this->links : null;
            }
        };

        $container = self::getContainer();

        // The categories ahead of the slot are read through the front API, which wants a request.
        $container->get('request_stack')->push(Request::create('/'));

        $header = new Header(
            $container->get(NavigationTree::class),
            new ContentSlotService([$resolver]),
            $container->get(LangService::class),
        );
        $header->mount();

        $slotItems = \array_slice($header->menuItems, -4);

        self::assertSame(['tree', 'tree', 'folder', 'link'], array_column($slotItems, 'type'));
        self::assertSame($agency, $slotItems[0]['link']);
        self::assertSame(['type' => 'folder', 'id' => 2, 'title' => 'Blog', 'href' => '/blog', 'includeContents' => true], $slotItems[2]);
        self::assertTrue($slotItems[3]['opensInNewWindow']);
    }

    private function render(ContentSlotLink $link, int $position = 0): Crawler
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return new Crawler($renderer->createAndRender('Organisms:HeaderMenuItem:Tree', ['link' => $link, 'position' => $position]));
    }
}
