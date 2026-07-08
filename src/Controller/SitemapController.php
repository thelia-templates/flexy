<?php

declare(strict_types=1);

namespace FlexyBundle\Controller;

use FlexyBundle\Service\SitemapGenerator;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SitemapController extends FlexyController
{
    #[Route('/sitemap.xml', name: 'front_sitemap_index')]
    public function index(SitemapGenerator $sitemapGenerator): Response
    {
        return $this->buildResponse($sitemapGenerator, 'index');
    }

    #[Route('/sitemap-{section}.xml', name: 'front_sitemap_section', requirements: ['section' => 'categories|products|images'])]
    public function section(string $section, SitemapGenerator $sitemapGenerator): Response
    {
        return $this->buildResponse($sitemapGenerator, $section);
    }

    /**
     * Backward compatibility with the former single sitemap URL.
     */
    #[Route('/sitemap', name: 'front_sitemap')]
    public function legacy(): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl('front_sitemap_index'), Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function buildResponse(SitemapGenerator $sitemapGenerator, string $section): Response
    {
        $flush = (bool) $this->getRequest()->query->get('flush', false);

        $cacheItem = $sitemapGenerator->generate($this->getParser(), $section, $flush);

        $response = new Response($cacheItem->get());
        $response->headers->set('Content-Type', 'application/xml');

        return $response;
    }
}
