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

namespace FlexyBundle\Service;

use Psr\Container\ContainerInterface;
use Symfony\Contracts\Service\ServiceSubscriberInterface;
use Thelia\Domain\Checkout\Exception\InvalidDeliveryException;
use Thelia\Domain\Shipping\DeliveryDate\DTO\DeliveryDateOffer;
use Thelia\Domain\Shipping\DeliveryDate\Service\DeliveryDateCalendar;
use Thelia\Domain\Shipping\DeliveryDate\Service\DeliveryDateGuard;
use Thelia\Domain\Shipping\DeliveryDate\Service\DeliveryDateSelection;
use Thelia\Model\Cart;
use Thelia\Model\DeliverySlotI18nQuery;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;

/**
 * What the theme asks the core about delivery dates, in one place.
 *
 * The core services are subscribed as optional: on a core that predates delivery dates they
 * are absent, every answer here is "no date", and the delivery step stays as it was. That is
 * what lets this theme run on the core it requires today and on the one that ships the
 * feature, without a check scattered in every component.
 */
final readonly class DeliveryDateBridge implements ServiceSubscriberInterface
{
    public function __construct(
        private ContainerInterface $locator,
    ) {
    }

    public static function getSubscribedServices(): array
    {
        return [
            'calendar' => '?'.DeliveryDateCalendar::class,
            'guard' => '?'.DeliveryDateGuard::class,
            'selection' => '?'.DeliveryDateSelection::class,
        ];
    }

    public function isAvailable(): bool
    {
        return $this->locator->has('calendar') && $this->locator->has('guard') && $this->locator->has('selection');
    }

    /**
     * none, date or slot.
     */
    public function choiceModeOf(?int $moduleId): string
    {
        $module = $this->moduleOf($moduleId);

        if (null === $module || !$this->isAvailable()) {
            return 'none';
        }

        return $this->calendar()->choiceModeOf($module)->value;
    }

    public function offerFor(?int $moduleId, ?string $locale): ?DeliveryDateOffer
    {
        $module = $this->moduleOf($moduleId);

        return null === $module || !$this->isAvailable() ? null : $this->calendar()->offerFor($module, locale: $locale);
    }

    /**
     * Whether the cart holds the day its carrier asks for, judged by the core guard.
     */
    public function isSettled(Cart $cart): bool
    {
        $module = $this->moduleOf($cart->getDeliveryModuleId());

        if (null === $module || !$this->isAvailable()) {
            return true;
        }

        try {
            $this->guard()->check($cart, $module);

            return true;
        } catch (InvalidDeliveryException) {
            return false;
        }
    }

    /**
     * @throws InvalidDeliveryException when the core refuses the day or the slot
     */
    public function choose(Cart $cart, ?string $date, ?int $slotId): void
    {
        if ($this->isAvailable()) {
            $this->selection()->choose($cart, $date, $slotId);
        }
    }

    public function dropIfNoLongerPossible(Cart $cart): bool
    {
        return $this->isAvailable() && $this->selection()->dropIfNoLongerPossible($cart);
    }

    /**
     * The day and slot the cart holds, for the summary of the tunnel.
     *
     * @return array{date: string, start: ?string, end: ?string, title: ?string}|null
     */
    public function chosenOn(Cart $cart, ?string $locale): ?array
    {
        if (!$this->isAvailable() || !method_exists($cart, 'getDeliveryDay') || null === $cart->getDeliveryDay()) {
            return null;
        }

        $slot = $cart->getDeliverySlotId() ? \Thelia\Model\DeliverySlotQuery::create()->findPk($cart->getDeliverySlotId()) : null;
        $title = null === $slot || null === $locale
            ? null
            : DeliverySlotI18nQuery::create()->filterById($slot->getId())->filterByLocale($locale)->findOne()?->getTitle();

        return [
            'date' => $cart->getDeliveryDay(),
            'start' => $slot?->getStartTime('H:i'),
            'end' => $slot?->getEndTime('H:i'),
            'title' => '' === trim((string) $title) ? null : $title,
        ];
    }

    private function moduleOf(?int $moduleId): ?Module
    {
        return null === $moduleId || $moduleId <= 0 ? null : ModuleQuery::create()->findPk($moduleId);
    }

    private function calendar(): DeliveryDateCalendar
    {
        return $this->locator->get('calendar');
    }

    private function guard(): DeliveryDateGuard
    {
        return $this->locator->get('guard');
    }

    private function selection(): DeliveryDateSelection
    {
        return $this->locator->get('selection');
    }
}
