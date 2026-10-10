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

use FlexyBundle\Service\FormService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\ComponentRendererInterface;

/**
 * The two-handle slider of a bounded facet. The price facet's slider writes its two amounts
 * under the track; any other slider stays the bare control it was.
 */
final class RangeSliderRenderTest extends KernelTestCase
{
    public function testASliderOfAmountsShowsTheLowerAndTheUpperAmount(): void
    {
        $slider = $this->render(['min' => 12, 'max' => 120, 'valueMin' => 20, 'valueMax' => 50, 'currency' => 'EUR', 'locale' => 'fr']);

        self::assertSame(['20 €', '50 €'], $this->amounts($slider));
        self::assertSame('20 €', $this->spaced((string) $slider->filter('input')->eq(0)->attr('aria-valuetext')));
        self::assertSame('50 €', $this->spaced((string) $slider->filter('input')->eq(1)->attr('aria-valuetext')));
        // Stimulus keeps the case of the controller name in the attribute; HTML attributes ignore it.
        self::assertStringContainsString('-currency-value="EUR"', $slider->filter('.RangeSlider')->outerHtml());
    }

    public function testCrossedHandlesStillReadFromTheLowerAmount(): void
    {
        $slider = $this->render(['min' => 12, 'max' => 120, 'valueMin' => 90, 'valueMax' => 30, 'currency' => 'EUR', 'locale' => 'fr']);

        self::assertSame(['30 €', '90 €'], $this->amounts($slider));
    }

    public function testASliderWithoutCurrencyShowsNoAmount(): void
    {
        $slider = $this->render(['min' => 100, 'max' => 300]);

        self::assertCount(0, $slider->filter('.RangeSlider-values'));
        self::assertNull($slider->filter('input')->eq(0)->attr('aria-valuetext'));
        self::assertStringNotContainsString('-currency-value', $slider->filter('.RangeSlider')->outerHtml());
    }

    public function testOnlyThePriceFacetIsGivenTheCurrency(): void
    {
        $this->pushRequest();

        /** @var FormService $formService */
        $formService = self::getContainer()->get(FormService::class);
        /** @var FormFactoryInterface $formFactory */
        $formFactory = self::getContainer()->get('form.factory');
        $builder = $formFactory->createNamedBuilder('tfilters', FormType::class);

        $bounds = [['id' => 1, 'title' => '12'], ['id' => 2, 'title' => '120']];
        $formService->renderFieldFromFieldType(['type' => 'price', 'id' => null, 'title' => 'Price', 'fieldType' => 'delta', 'values' => $bounds], $builder, []);
        $formService->renderFieldFromFieldType(['type' => 'feature', 'id' => 7, 'title' => 'Weight', 'fieldType' => 'delta', 'values' => $bounds], $builder, []);

        self::assertArrayHasKey('data-currency', $builder->get('price')->get('price')->getOption('attr'));
        self::assertSame([], $builder->get('feature')->get('7')->getOption('attr'));
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props): Crawler
    {
        $this->pushRequest();

        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return new Crawler($renderer->createAndRender(
            'Fields:RangeSlider:Base',
            ['nameMin' => 'tfilters[price][price][min]', 'nameMax' => 'tfilters[price][price][max]'] + $props,
        ));
    }

    /**
     * @return list<string>
     */
    private function amounts(Crawler $slider): array
    {
        return $slider->filter('.RangeSlider-values span')->each(fn (Crawler $amount): string => $this->spaced($amount->text()));
    }

    /**
     * French amounts are written with non-breaking spaces; compared as plain spaces.
     */
    private function spaced(string $amount): string
    {
        return str_replace(["\u{202F}", "\u{00A0}"], ' ', $amount);
    }

    private function pushRequest(): void
    {
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push(Request::create('/'));
    }
}
