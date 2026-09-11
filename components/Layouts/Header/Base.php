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

namespace FlexyBundle\Components\Layouts\Header;

use CustomFrontMenu\Service\Front\MenuTreeResolver;
use FlexyBundle\Service\NavigationTree;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Core\HttpFoundation\Session\Session;

#[AsTwigComponent]
class Base
{
    public array $menuItems = [];

    public function __construct(
        private readonly DataAccessService $dataAccessService,
        private readonly NavigationTree $navigationTree,
        private readonly MenuTreeResolver $treeResolver,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function mount(): void
    {
        /** @var Session|null $session */
        $session = $this->requestStack->getCurrentRequest()?->getSession();

        $locale = $session?->getLang()?->getLocale() ?? 'en_US';

        $menu = $this->treeResolver->resolve('header', $locale);

        $this->menuItems = array_map(
            static fn (array $item): array => [
                'id' => $item['id'],
                'title' => $item['title'],
                'href' => $item['href'],
                'children' => $item['children'],
            ],
            $menu,
        );
    }
}
