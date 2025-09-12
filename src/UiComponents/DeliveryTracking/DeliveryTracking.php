<?php

declare(strict_types=1);

namespace FlexyBundle\UiComponents\DeliveryTracking;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'Flexy:DeliveryTracking', template: '@UiComponents/DeliveryTracking/DeliveryTracking.html.twig')]
class DeliveryTracking
{
    public const STEPS = ['Order validated', 'Order in preparation', 'Order shipped', 'Order delivered'];

    public ?string $ref;
    public ?int $status = 1;
    public ?string $trackLink;

    public function getSteps(): array
    {
        return self::STEPS;
    }
}
