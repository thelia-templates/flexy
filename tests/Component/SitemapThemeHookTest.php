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

use FlexyBundle\Service\SitemapGenerator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Template\TemplateHelperInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * A module adds its addresses to the sitemap through the `sitemap.urls` theme hook: every
 * urlset section calls it with its own name as the context, and the "content" section is
 * listed in the index for the addresses only modules know (the pages of a CMS).
 */
final class SitemapThemeHookTest extends KernelTestCase
{
    public function testTheIndexListsTheContentSection(): void
    {
        self::assertStringContainsString('/sitemap-content.xml</loc>', $this->generate('index'));
    }

    public function testTheContentSectionIsAWellFormedUrlsetWhenNoModuleAnswers(): void
    {
        $xml = $this->generate('content');

        self::assertNotFalse(simplexml_load_string($xml), 'The section must stay a well-formed document.');
        self::assertStringContainsString('<urlset', $xml);
    }

    /**
     * The handlers of a theme hook are collected when the container is compiled, so the
     * template is rendered here on its own, with a theme_hook() that records its calls.
     */
    public function testAUrlsetAsksTheModulesWithItsSectionAndKeepsWhatTheyAnswer(): void
    {
        $calls = [];

        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__, 2)));
        $twig->addFunction(new TwigFunction(
            'theme_hook',
            static function (string $hookName, array $parameters = []) use (&$calls): string {
                $calls[] = [$hookName, $parameters];

                return '<url><loc>https://shop.example/agency</loc><xhtml:link rel="alternate" hreflang="fr-FR" href="https://shop.example/agence"/></url>';
            },
            ['is_safe' => ['html']],
        ));

        $xml = $twig->render('sitemap-urlset.html.twig', ['urls' => [], 'section' => 'content']);

        self::assertSame([['sitemap.urls', ['context' => 'content', 'lang' => '']]], $calls);
        self::assertStringContainsString('<loc>https://shop.example/agency</loc>', $xml);
        self::assertNotFalse(simplexml_load_string($xml), 'What a module adds, languages included, must leave a well-formed document.');
    }

    /**
     * The Sitemap module active on a shop showing several languages, each entry names its
     * language versions; otherwise the entry is printed as it always was, without a link.
     */
    public function testAnEntryPrintsItsLanguageAlternatesOnlyWhenItHasSome(): void
    {
        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__, 2)));
        $twig->addFunction(new TwigFunction('theme_hook', static fn (): string => '', ['is_safe' => ['html']]));

        $plain = ['loc' => 'https://shop.example/chair.html', 'lastmod' => null, 'priority' => null, 'changefreq' => null, 'alternates' => []];
        $localized = ['loc' => 'https://shop.example/chaise.html', 'alternates' => [
            ['hreflang' => 'fr', 'href' => 'https://shop.example/chaise.html'],
            ['hreflang' => 'en', 'href' => 'https://shop.example/chair.html'],
            ['hreflang' => 'x-default', 'href' => 'https://shop.example/chair.html'],
            ['hreflang' => 'es', 'href' => 'https://shop.example/?view=product&lang=es_ES&product_id=1'],
        ]] + $plain;

        $xml = $twig->render('sitemap-urlset.html.twig', ['urls' => [$plain], 'section' => 'products']);
        self::assertStringNotContainsString('xhtml:link', $xml);

        $xml = $twig->render('sitemap-urlset.html.twig', ['urls' => [$localized], 'section' => 'products']);
        $document = simplexml_load_string($xml);
        self::assertNotFalse($document, 'An address carrying a query string must leave a well-formed document.');

        $links = $document->url[0]->children('http://www.w3.org/1999/xhtml')->link;
        self::assertCount(4, $links);
        self::assertSame('x-default', (string) $links[2]->attributes()->hreflang);
        self::assertSame('https://shop.example/?view=product&lang=es_ES&product_id=1', (string) $links[3]->attributes()->href);
    }

    private function generate(string $section): string
    {
        $container = self::getContainer();

        // The index writes absolute addresses through the URL singleton, which a request
        // sets up in the shop and nothing does in a bare kernel.
        $container->get('thelia.url.manager');

        $template = $container->get(TemplateHelperInterface::class)->getActiveFrontTemplate();

        $parser = $container->get(ParserResolver::class)->getParser($template->getAbsolutePath(), null);
        $parser->setTemplateDefinition($template, true);

        return (string) $container->get(SitemapGenerator::class)->generate($parser, $section, true)->get();
    }
}
