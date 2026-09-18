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
use Symfony\UX\TwigComponent\ComponentRendererInterface;

/**
 * The pager as its callers use it: two of the three mount it as a component, so its props
 * have to reach the template through the class.
 */
final class PaginationRenderTest extends KernelTestCase
{
    private function render(array $props): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender('Molecules:Pagination:Base', $props);
    }

    private function pageLinks(string $html): array
    {
        preg_match_all('/<(a|span)[^>]*class="[^"]*Pagination-item[^"]*"[^>]*>\s*(\d+)\s*</', $html, $m);

        return array_map('intval', $m[2]);
    }

    public function testMountedAsAComponentItRendersItsPages(): void
    {
        $html = $this->render(['totalItems' => 100, 'itemsPerPage' => 10, 'currentPage' => 1]);

        self::assertStringContainsString('Pagination', $html, 'the nav is rendered');
        self::assertNotEmpty($this->pageLinks($html), 'the page numbers are rendered');
    }

    public function testAPageSizeOfZeroRendersNothingAndDividesByNothing(): void
    {
        self::assertSame('', trim($this->render(['totalItems' => 100, 'itemsPerPage' => 0, 'currentPage' => 1])));
    }

    public function testASinglePageRendersNoNav(): void
    {
        self::assertSame('', trim($this->render(['totalItems' => 5, 'itemsPerPage' => 10, 'currentPage' => 1])));
    }

    public function testALargeCatalogueDoesNotPrintOneLinkPerPage(): void
    {
        $links = $this->pageLinks($this->render(['totalItems' => 5000, 'itemsPerPage' => 10, 'currentPage' => 50]));

        self::assertLessThanOrEqual(7, \count($links), 'the window is bounded');
        self::assertContains(1, $links, 'the first page is always reachable');
        self::assertContains(500, $links, 'the last page is always reachable');
        self::assertContains(50, $links, 'the current page is in the window');
    }

    /** The number is visitor input, so both ends need bounding. */
    public function testAPageNumberPastTheLastOneIsClampedToTheLast(): void
    {
        $html = $this->render(['totalItems' => 100, 'itemsPerPage' => 10, 'currentPage' => 999]);

        preg_match('/aria-current="page"[^>]*>\s*(\d+)\s*</', $html, $current);
        self::assertNotEmpty($current, 'a current page is marked');
        self::assertSame(10, (int) $current[1]);
    }

    public function testAPageNumberBelowOneIsClampedToOne(): void
    {
        $html = $this->render(['totalItems' => 100, 'itemsPerPage' => 10, 'currentPage' => -3]);

        preg_match('/aria-current="page"[^>]*>\s*(\d+)\s*</', $html, $current);
        self::assertNotEmpty($current, 'a current page is marked');
        self::assertSame(1, (int) $current[1]);
    }

    public function testTheCurrentPageIsMarkedAndIsNotALink(): void
    {
        $html = $this->render(['totalItems' => 100, 'itemsPerPage' => 10, 'currentPage' => 3]);

        self::assertStringContainsString('aria-current="page"', $html);
        self::assertDoesNotMatchRegularExpression(
            '/<a[^>]*aria-current="page"/',
            $html,
            'the current page is not a link'
        );
    }

    public function testTheGapBetweenTwoDistantPagesIsHiddenFromAssistiveTechnology(): void
    {
        $html = $this->render(['totalItems' => 5000, 'itemsPerPage' => 10, 'currentPage' => 50]);

        preg_match('/<[a-z]+[^>]*Pagination-gap[^>]*>/', $html, $gap);

        self::assertNotEmpty($gap, 'a gap is rendered between distant pages');
        self::assertStringContainsString('aria-hidden="true"', $gap[0]);
    }
}
