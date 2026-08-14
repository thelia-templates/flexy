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

namespace FlexyBundle\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Front\DefaultController;
use Thelia\Core\HttpFoundation\Request;

/**
 * The front-office catch-all: it reads the last segment of a URL as the name of a view and
 * lets the core render it. Every page that is not a route of its own arrives here, either
 * named directly or through the rewriting router, which resolves a SEO URL and hands over
 * the view it points at. Without it a shop serves no category, product, content or folder.
 *
 * This used to be declared by the Front module, which the theme no longer requires. The
 * work itself stays in the core: the action below only carries the route to it.
 *
 * Declared last on purpose. The pattern swallows any single segment, so any route matching
 * it would become unreachable if this one were tried first. `admin` and `api` are excluded
 * by the pattern rather than by the ordering, because both are served by routers that only
 * run once this one has been tried.
 */
class ViewController extends DefaultController
{
    #[Route(
        '/{_view}',
        name: 'flexy_view',
        requirements: ['_view' => '^(?!admin|api)[^/]+'],
        defaults: ['_view' => 'index'],
        priority: -1000,
    )]
    public function view(Request $request): void
    {
        $this->noAction($request);
    }
}
