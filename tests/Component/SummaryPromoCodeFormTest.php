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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\HttpFoundation\Session\Session;
use Symfony\UX\LiveComponent\LiveComponentHydrator;
use Symfony\UX\LiveComponent\Metadata\LiveComponentMetadataFactory;
use Symfony\UX\TwigComponent\ComponentFactory;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Symfony\UX\TwigComponent\MountedComponent;

/**
 * A checkout summary mounted without the promo code form keeps it hidden when it redraws itself on one of its own
 * listeners (`syncSummary`, an item added or removed): the choice travels with the live state, the way a re-render
 * rebuilds the component, instead of being a template prop the re-render forgets.
 */
final class SummaryPromoCodeFormTest extends KernelTestCase
{
    private const string NAME = 'Organisms:Summary:Checkout';

    protected function setUp(): void
    {
        // The summary reads the cart of the session: an empty one is enough here.
        $request = Request::create('/checkout/delivery');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);
        // Part of the context the Thelia parser hands a page template, which a component rendered alone does not get.
        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');
    }

    public function testTheFormStaysHiddenWhenTheSummaryRedrawsItself(): void
    {
        $mounted = $this->factory()->create(self::NAME, ['id' => 'summary', 'showPromoCodeForm' => false]);

        self::assertStringNotContainsString('class="PromoCode', $this->renderer()->render($mounted), 'mounted without the form');
        self::assertStringNotContainsString('class="PromoCode', $this->redraw($mounted), 'still without it once redrawn');
    }

    public function testTheFormIsShownByDefault(): void
    {
        $mounted = $this->factory()->create(self::NAME, ['id' => 'summary']);

        self::assertStringContainsString('class="PromoCode', $this->redraw($mounted));
    }

    /**
     * What a live re-render does: the props the page sends back, hydrated on a fresh component, then rendered.
     */
    private function redraw(MountedComponent $mounted): string
    {
        /** @var LiveComponentHydrator $hydrator */
        $hydrator = self::getContainer()->get('ux.live_component.component_hydrator');
        /** @var LiveComponentMetadataFactory $metadataFactory */
        $metadataFactory = self::getContainer()->get('ux.live_component.metadata_factory');
        $metadata = $metadataFactory->getMetadata(self::NAME);

        $props = $hydrator->dehydrate($mounted->getComponent(), $mounted->getAttributes(), $metadata)->getProps();
        $fresh = $this->factory()->get(self::NAME);
        $attributes = $hydrator->hydrate($fresh, $props, [], $metadata);

        return $this->renderer()->render(new MountedComponent(self::NAME, $fresh, $attributes));
    }

    private function factory(): ComponentFactory
    {
        return self::getContainer()->get('ux.twig_component.component_factory');
    }

    private function renderer(): ComponentRendererInterface
    {
        return self::getContainer()->get('ux.twig_component.component_renderer');
    }
}
