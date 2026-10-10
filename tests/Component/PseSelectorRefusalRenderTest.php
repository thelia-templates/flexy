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
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\UX\TwigComponent\ComponentRendererInterface;

/**
 * A refused add tells the shopper the theme's sentence, then the module's reason. SnackBar prints
 * its text raw: the reason is the module's, so it must come out as text, never as markup.
 */
final class PseSelectorRefusalRenderTest extends KernelTestCase
{
    public function testTheModuleReasonFollowsTheSentenceAsText(): void
    {
        $alert = $this->alert(['cartRefused' => true, 'cartRefusalReason' => 'Sold <b>by</b> 6']);

        self::assertStringContainsString('Sold <b>by</b> 6', $alert->text());
        self::assertCount(0, $alert->filter('b'));
    }

    public function testARefusalWithoutReasonKeepsTheSentenceAlone(): void
    {
        self::assertStringNotContainsString('Sold', $this->alert(['cartRefused' => true])->text());
    }

    private function alert(array $props): Crawler
    {
        $builder = self::getContainer()->get(FormFactoryInterface::class)
            ->createNamedBuilder('thelia_cart_add', FormType::class, null, ['csrf_protection' => false]);

        foreach (['product', 'product_sale_elements_id', 'quantity', 'append', 'newness'] as $field) {
            $builder->add($field, TextType::class);
        }

        // The price below the selector reads the language Thelia's parser publishes for a page.
        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');

        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');
        $html = $renderer->createAndRender('Organisms:PseSelector:Base', [
            'form' => $builder->getForm()->createView(),
            'currentPse' => ['id' => 7, 'quantity' => 3, 'isPromo' => false, 'price' => 10.0, 'promoPrice' => 10.0],
            ...$props,
        ]);

        $alert = (new Crawler($html))->filter('[role="alert"]');
        self::assertCount(1, $alert, 'the refusal is announced once');

        return $alert;
    }
}
