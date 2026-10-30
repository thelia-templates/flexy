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

use FlexyBundle\EventListener\QuantityAdjustedFlashListener;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\ComponentRendererInterface;

/**
 * The lines the core adjusted are told once, as a status naming the product, then forgotten.
 * The title and the sentence come out as text.
 */
final class QuantityAdjustmentsRenderTest extends KernelTestCase
{
    public function testEachAdjustedLineIsToldOnceAsAStatus(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->getFlashBag()->add(QuantityAdjustedFlashListener::FLASH_KEY, ['product' => 'Wine <b>case</b>', 'message' => 'The quantity was brought down to 6: sold by 6.']);
        $request = new Request();
        $request->setSession($session);
        $requests = self::getContainer()->get('request_stack');
        \assert($requests instanceof RequestStack);
        $requests->push($request);

        $status = (new Crawler($this->render()))->filter('[role="status"]');

        self::assertCount(1, $status);
        self::assertStringContainsString('The quantity of Wine <b>case</b> in your cart was adjusted. The quantity was brought down to 6: sold by 6.', $status->text());
        self::assertCount(0, $status->filter('b'));
        self::assertSame('', trim($this->render()), 'told once');
    }

    public function testAPageFetchedAheadOfAClickLeavesTheMessageForThePageThatIsOpened(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->getFlashBag()->add(QuantityAdjustedFlashListener::FLASH_KEY, ['product' => 'Wine case', 'message' => 'The quantity was brought down to 6: sold by 6.']);
        $requests = self::getContainer()->get('request_stack');
        \assert($requests instanceof RequestStack);

        $prefetch = new Request();
        $prefetch->headers->set('X-Sec-Purpose', 'prefetch');
        $prefetch->setSession($session);
        $requests->push($prefetch);
        self::assertSame('', trim($this->render()), 'nobody sees a prefetched page');
        $requests->pop();

        $visit = new Request();
        $visit->setSession($session);
        $requests->push($visit);
        self::assertStringContainsString('Wine case', (new Crawler($this->render()))->filter('[role="status"]')->text());
    }

    public function testNothingToTellRendersNothing(): void
    {
        self::assertSame('', trim($this->render()));
    }

    private function render(): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender('Organisms:QuantityAdjustments:Base');
    }
}
