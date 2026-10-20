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
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\HttpFoundation\Session\Session;

/** The purchase list dialogs are named by the title they show, so a screen reader says which dialog opened. */
final class PurchaseListModalsNameTest extends KernelTestCase
{
    protected function setUp(): void
    {
        // The components read the session (cart, customer): an empty one is enough here.
        $request = Request::create('/account/purchase-lists');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);
        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');
    }

    public function testTheSaveDialogIsNamedByItsTitle(): void
    {
        $this->assertEveryDialogIsNamed($this->render('Organisms:SaveToPurchaseList:Base', ['action' => '/save', 'signedIn' => true]));
    }

    public function testTheDeleteDialogOfAListIsNamedByItsTitle(): void
    {
        $list = ['id' => 5, 'title' => 'Weekly order', 'itemCount' => 2, 'updatedAt' => null, 'writable' => true];

        $this->assertEveryDialogIsNamed($this->render('Layouts:AccountPurchaseLists:Base', ['lists' => [$list]]));
    }

    public function testTheQuickOrderSaveDialogIsNamedByItsTitle(): void
    {
        // A live component needs the template that mounts it to compute its id.
        $html = self::getContainer()->get('twig')->createTemplate('<twig:Organisms:QuickOrderTable:Base />')->render();

        $this->assertEveryDialogIsNamed($html);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(string $component, array $props): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender($component, $props);
    }

    private function assertEveryDialogIsNamed(string $html): void
    {
        $page = new Crawler($html);
        $dialogs = $page->filter('dialog');

        self::assertGreaterThan(0, $dialogs->count(), 'the component renders a dialog');

        foreach ($dialogs as $dialog) {
            $labelledBy = $dialog->getAttribute('aria-labelledby');

            self::assertNotSame('', $labelledBy, sprintf('the dialog "%s" has no accessible name', $dialog->getAttribute('id')));
            self::assertCount(1, $page->filter('#'.$labelledBy), 'the name points at a title that exists');
            self::assertNotSame('', trim($page->filter('#'.$labelledBy)->text()), 'the title is not empty');
        }
    }
}
