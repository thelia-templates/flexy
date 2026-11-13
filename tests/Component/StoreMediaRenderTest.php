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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\ConfigQuery;
use Thelia\Tools\URL;
use Twig\Environment;

/**
 * The logo and the favicon saved in the store information are the ones the storefront shows;
 * without them, the theme keeps its own mark and its own icons.
 */
final class StoreMediaRenderTest extends KernelTestCase
{
    private const string LOGO = 'flexy-test-logo.png';

    private const string FAVICON = 'flexy-test-favicon.png';

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();

        // A page request makes the URL service; the cache url of the images is built from it.
        self::getContainer()->get(URL::class);
    }

    protected function tearDown(): void
    {
        foreach ([self::LOGO, self::FAVICON] as $file) {
            @unlink($this->storeDirectory().\DIRECTORY_SEPARATOR.$file);
        }

        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testTheHeaderShowsTheLogoOfTheStore(): void
    {
        $this->saveStoreMedia(logo: self::LOGO);

        $header = $this->render('Layouts:Header:Base');

        self::assertStringContainsString(self::LOGO, (string) $header->filter('.Header-logo img')->first()->attr('src'));
        self::assertCount(0, $header->filter('.Header-logo svg'));
    }

    public function testTheCheckoutHeaderShowsTheLogoOfTheStore(): void
    {
        $this->saveStoreMedia(logo: self::LOGO);

        $header = $this->render('Layouts:Header:Checkout');

        self::assertStringContainsString(self::LOGO, (string) $header->filter('.HeaderCheckout-logo img')->attr('src'));
        self::assertCount(0, $header->filter('.HeaderCheckout-logo svg'));
    }

    public function testWithoutALogoTheHeadersKeepTheMarkOfTheTheme(): void
    {
        $this->saveStoreMedia();

        foreach ([['Layouts:Header:Base', '.Header-logo'], ['Layouts:Header:Checkout', '.HeaderCheckout-logo']] as [$component, $link]) {
            $header = $this->render($component);

            self::assertCount(0, $header->filter($link.' img'), $component);
            self::assertGreaterThan(0, \count($header->filter($link.' svg')), $component);
        }
    }

    public function testTheTabShowsTheFaviconOfTheStore(): void
    {
        $this->saveStoreMedia(favicon: self::FAVICON);

        $icons = $this->favicons();

        self::assertCount(1, $icons->filter('link[rel="icon"]'));
        self::assertStringContainsString(self::FAVICON, (string) $icons->filter('link[rel="icon"]')->attr('href'));
        self::assertSame('image/png', $icons->filter('link[rel="icon"]')->attr('type'));
        self::assertStringContainsString(self::FAVICON, (string) $icons->filter('link[rel="shortcut icon"]')->attr('href'));
    }

    public function testWithoutAFaviconTheTabKeepsTheIconsOfTheTheme(): void
    {
        $this->saveStoreMedia();

        $icons = $this->favicons();

        self::assertCount(2, $icons->filter('link[rel="icon"]'));
        self::assertStringContainsString('favicon-96x96', (string) $icons->filter('link[rel="icon"]')->first()->attr('href'));
        self::assertStringContainsString('favicon', (string) $icons->filter('link[rel="shortcut icon"]')->attr('href'));
        self::assertStringNotContainsString('flexy-test', $icons->html());
    }

    private function saveStoreMedia(?string $logo = null, ?string $favicon = null): void
    {
        $directory = $this->storeDirectory();
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $configs = ConfigQuery::findAllAsMap();
        unset($configs['logo_file'], $configs['favicon_file']);

        foreach (['logo_file' => $logo, 'favicon_file' => $favicon] as $name => $file) {
            if (null === $file) {
                continue;
            }

            $image = imagecreatetruecolor(64, 64);
            imagepng($image, $directory.\DIRECTORY_SEPARATOR.$file);
            $configs[$name] = $file;
        }

        // The configuration is read through a cache the kernel warms from the database: the
        // files are named in that cache only, so the database is left as it was.
        ConfigQuery::initCache($configs);
    }

    private function storeDirectory(): string
    {
        $library = ConfigQuery::read('images_library_path');

        return (\is_string($library) && '' !== $library ? THELIA_ROOT.$library : THELIA_LOCAL_DIR.'media'.\DIRECTORY_SEPARATOR.'images').\DIRECTORY_SEPARATOR.'store';
    }

    private function render(string $component): Crawler
    {
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return new Crawler($renderer->createAndRender($component));
    }

    private function favicons(): Crawler
    {
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return new Crawler('<div>'.$twig->load('@theme_flexy/base.html.twig')->renderBlock('favicons').'</div>');
    }
}
