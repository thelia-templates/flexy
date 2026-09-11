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
use Thelia\Core\HttpFoundation\Session\Session;

#[AsTwigComponent]
class Base
{
    /**
     * The menu a shop owner composes in the back-office. A theme addresses a menu by its
     * code, so this is the one place to change to point the header somewhere else.
     */
    private const MENU_CODE = 'header';

    public array $menuItems = [];

    /**
     * CustomFrontMenu is optional: the parameter carries a default so the container keeps
     * the null when the module is absent or deactivated, instead of failing to build this
     * component — and with it every page of the shop.
     */
    public function __construct(
        private readonly NavigationTree $navigationTree,
        private readonly RequestStack $requestStack,
        private readonly ?MenuTreeResolver $treeResolver = null,
    ) {
    }

    public function mount(): void
    {
        // No module, or no menu under that code: the navigation falls back to the
        // catalogue, which is what a shop that never composed a menu should show.
        $this->menuItems = $this->treeResolver?->resolve(self::MENU_CODE, $this->locale())
            ?? $this->navigationTree->categoryRoots();
    }

    private function locale(): string
    {
        /** @var Session|null $session */
        $session = $this->requestStack->getCurrentRequest()?->getSession();

        return $session?->getLang()?->getLocale() ?? 'en_US';
    }
}
