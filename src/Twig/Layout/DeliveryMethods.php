<?php

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlexyBundle\Twig\Layout;

use FlexyBundle\Service\ModuleService;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(template: '@components/Layout/DeliveryMethods/DeliveryMethods.html.twig')]
class DeliveryMethods
{
    use DefaultActionTrait;

    #[LiveProp]
    public array $modules = [];

    public function __construct(
        protected readonly ModuleService $moduleService
    ) {
    }

    public function mount(): void
    {
        $this->modules = $this->moduleService->getDeliveryModuleList();
    }
}
