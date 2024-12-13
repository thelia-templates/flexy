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

namespace FlexyBundle\Service;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Map\TableMap;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Module\AbstractDeliveryModule;
use Thelia\Module\BaseModule;
use Thelia\Tools\URL;

class ModuleService
{
    public function __construct(protected readonly ContainerInterface $container, protected readonly Session $session)
    {
    }

    public function getModuleList(string $moduleType = null, int $area = null): array|ObjectCollection
    {
        $search = ModuleQuery::create();

        if (null !== $area) {
            $search
              ->useAreaDeliveryModuleQuery()
              ->filterByAreaId($area, Criteria::IN)
              ->endUse();
        }

        if (null !== $moduleType) {
            $search->filterByType($moduleType, Criteria::IN);
        }

        $search->filterByActivate(1);

        return $search->find();
    }

    /**
     * @param array|ObjectCollection|Module[] $modules
     */
    protected function getModuleDeliveryByModes(array|ObjectCollection $modules): array
    {
        $list = [];
        foreach ($modules as $module) {
            /** @var AbstractDeliveryModule $moduleInstance */
            $moduleInstance = $module->getDeliveryModuleInstance($this->container);
            $mode = $moduleInstance->getDeliveryMode();
            if (!isset($list[$mode])) {
                $list[$mode] = [];
            }
            $locale = $this->session->getLang(true)->getLocale();
            $module->setLocale($locale);

            $list[$mode][] = [
              ...$module->toArray(TableMap::TYPE_CAMELNAME),
              'title' => $module->getTitle(),
              'mode' => $mode,
              'img' => $this->getModuleLogoUrl($module),
            ];
        }

        return $list;
    }

    public function getModuleLogoUrl(Module $module, $region = 'full', $size = '%5E*!40,40')
    {
        $imageId = $module->getModuleImages()->getFirst()?->getId();

        return URL::getInstance()->absoluteUrl('legacy-image-library'.DS.'module_image_'.$imageId.DS.$region.DS.$size.DS.'0'.DS.'default.webp');
    }

    public function getDeliveryModuleList(): array
    {
        $modules = $this->getModuleList(moduleType: BaseModule::DELIVERY_MODULE_TYPE);

        return $this->getModuleDeliveryByModes($modules);
    }
}
