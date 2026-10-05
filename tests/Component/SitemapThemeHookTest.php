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
