<?php

namespace FlexyBundle\Service;

use ChronopostPickupPoint\ChronopostPickupPoint;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\ModuleQuery;

class DeliveryService
{
    public function __construct(private readonly Session $session, private readonly ContainerInterface $container) {}

    public function setPickupSession(?array $pickup): void
    {
        if ($pickup) {
            $this->session->set('pickup_address', $pickup['address']);
            $this->session->set('pickup', $pickup);
            return;
        }
        $this->session->remove('pickup_address');
        $this->session->remove('pickup');
    }

    public function isValid(?int $moduleId): bool
    {
        if (!$moduleId) return true;
        $module = ModuleQuery::create()->findPk($moduleId);
        $instance = $module->getModuleInstance($this->container);
        if ($instance?->getDeliveryMode() !== "pickup") {
            return true;
        }
        return $this->session->has('pickup_address');
    }
}
