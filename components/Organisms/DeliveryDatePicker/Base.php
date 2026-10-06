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

namespace FlexyBundle\Components\Organisms\DeliveryDatePicker;

use FlexyBundle\Event\CheckoutEvents;
use FlexyBundle\Service\DeliveryDateBridge;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Checkout\Exception\DeliveryDateUnavailableException;
use Thelia\Domain\Checkout\Exception\InvalidDeliveryException;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Shipping\DeliveryDate\DTO\DeliveryDateOffer;
use Thelia\Domain\Shipping\DeliveryDate\DTO\DeliveryDay;
use Thelia\Domain\Shipping\DeliveryDate\DTO\DeliverySlotOffer;

/**
 * The day, then the slot, under the carrier the buyer selected, for a carrier that offers
 * delivery dates.
 *
 * The grid is drawn by the server from the days the core computes, closed and full days
 * disabled: no date is ever computed in the browser, so the day shown is the day stored,
 * whatever the time zone of the visitor. Every choice is judged again by the core when it
 * is posted and when the order is placed: what this grid offered proves nothing.
 *
 * Nothing at all is rendered for a carrier that offers no date.
 */
#[AsLiveComponent]
class Base
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp(updateFromParent: true)]
    public int $moduleId = 0;

    /**
     * The day picked for a carrier of slots, until its slot is: only a day and its slot
     * together are written on the cart.
     */
    #[LiveProp]
    public ?string $pendingDay = null;

    /**
     * Which month of the window is shown, 0 for the first.
     */
    #[LiveProp]
    public int $monthPage = 0;

    /**
     * Why the last choice was refused — a slot filled in the meantime, a day no longer
     * offered. Held as a prop to survive the re-render that follows the refusal.
     */
    #[LiveProp]
    public ?string $error = null;

    private ?DeliveryDateOffer $offer = null;

    private bool $offerRead = false;

    public function __construct(
        private readonly DeliveryDateBridge $deliveryDates,
        private readonly CartFacade $cartFacade,
        private readonly LangService $langService,
    ) {
    }

    public function mount(int $moduleId): void
    {
        $this->moduleId = $moduleId;
        $chosen = $this->getChosenDay();

        foreach ($this->getMonths() as $page => $month) {
            if (null !== $chosen && str_starts_with($chosen, $month['key'])) {
                $this->monthPage = $page;
            }
        }
    }

    public function getOffer(): ?DeliveryDateOffer
    {
        if (!$this->offerRead) {
            $this->offer = $this->deliveryDates->offerFor($this->moduleId, $this->langService->getLocale());
            $this->offerRead = true;
        }

        return $this->offer;
    }

    public function getChoiceMode(): string
    {
        return $this->getOffer()?->choiceMode->value ?? 'none';
    }

    public function getChosenDay(): ?string
    {
        $cart = $this->cartFacade->getOrCreateFromSession();

        return method_exists($cart, 'getDeliveryDay') && $cart->getDeliveryModuleId() === $this->moduleId ? $cart->getDeliveryDay() : null;
    }

    public function getChosenSlotId(): ?int
    {
        $slotId = null === $this->getChosenDay() ? null : $this->cartFacade->getOrCreateFromSession()->getDeliverySlotId();

        return null === $slotId ? null : (int) $slotId;
    }

    /**
     * The day whose slots are listed: the one being picked, or the one the cart holds.
     */
    public function getVisibleDay(): ?DeliveryDay
    {
        $date = $this->pendingDay ?? $this->getChosenDay();

        return null === $date ? null : $this->getOffer()?->day(new \DateTimeImmutable($date));
    }

    /**
     * The window cut into months, each into weeks of seven cells starting on Monday, with
     * null for the cells outside the window.
     *
     * @return list<array{key: string, first: \DateTimeImmutable, weeks: list<list<?DeliveryDay>>}>
     */
    public function getMonths(): array
    {
        $byMonth = [];

        foreach ($this->getOffer()?->days ?? [] as $day) {
            $byMonth[$day->date->format('Y-m')][] = $day;
        }

        $months = [];

        foreach ($byMonth as $key => $days) {
            $cells = array_fill(0, (int) $days[0]->date->format('N') - 1, null);
            array_push($cells, ...$days);

            while (0 !== \count($cells) % 7) {
                $cells[] = null;
            }

            $months[] = ['key' => $key, 'first' => $days[0]->date, 'weeks' => array_chunk($cells, 7)];
        }

        return $months;
    }

    /**
     * @return array{key: string, first: \DateTimeImmutable, weeks: list<list<?DeliveryDay>>}|null
     */
    public function getCurrentMonth(): ?array
    {
        $months = $this->getMonths();

        return $months[max(0, min($this->monthPage, \count($months) - 1))] ?? null;
    }

    /**
     * @return list<DeliverySlotOffer>
     */
    public function getVisibleSlots(): array
    {
        return $this->getVisibleDay()?->slots ?? [];
    }

    #[LiveAction]
    public function showMonth(#[LiveArg] int $page): void
    {
        $this->monthPage = max(0, min($page, \count($this->getMonths()) - 1));
    }

    #[LiveAction]
    public function chooseDay(#[LiveArg] string $date): void
    {
        $this->error = null;
        $cart = $this->cartFacade->getOrCreateFromSession();

        // The day comes from the browser: one this grid does not offer is not kept, not even
        // as the day whose slots are listed. A cart that moved to another carrier in another
        // tab is not written by this one's calendar.
        if (!$this->isForTheCarrierOfTheCart() || null === $this->offeredDay($date)) {
            $this->error = $this->unavailableMessage();
            $this->emit('updateNextButton');

            return;
        }

        if ('slot' === $this->getChoiceMode()) {
            // A day alone is not a choice for a carrier of slots: the one the cart held
            // goes, so the step cannot be passed with a slot of another day.
            $this->pendingDay = $date;

            if (null !== $this->getChosenDay() && $this->getChosenDay() !== $date) {
                $this->deliveryDates->choose($cart, null, null);
            }
        } else {
            $this->write($date, null);
        }

        $this->emit('updateNextButton');
        $this->emit('syncSummary');
    }

    #[LiveAction]
    public function chooseSlot(#[LiveArg] string $date, #[LiveArg] int $slotId): void
    {
        $this->error = null;

        if (!$this->isForTheCarrierOfTheCart()) {
            $this->error = $this->unavailableMessage();

            return;
        }

        if ($this->write($date, $slotId)) {
            $this->pendingDay = null;
        }

        $this->emit('updateNextButton');
        $this->emit('syncSummary');
    }

    private function isForTheCarrierOfTheCart(): bool
    {
        return $this->cartFacade->getOrCreateFromSession()->getDeliveryModuleId() === $this->moduleId;
    }

    private function offeredDay(string $date): ?DeliveryDay
    {
        $day = 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;

        if (false === $day || $day->format('Y-m-d') !== $date) {
            return null;
        }

        $offered = $this->getOffer()?->day($day);

        return null !== $offered && $offered->available ? $offered : null;
    }

    private function unavailableMessage(): string
    {
        return (new DeliveryDateUnavailableException())->getMessage();
    }

    private function write(string $date, ?int $slotId): bool
    {
        try {
            $this->deliveryDates->choose($this->cartFacade->getOrCreateFromSession(), $date, $slotId);
            $this->offerRead = false;
            // The step drops its notice about a day that went: the buyer just picked another.
            $this->emit(CheckoutEvents::DELIVERY_DATE_CHOSEN);

            return true;
        } catch (InvalidDeliveryException $refusal) {
            $this->error = $refusal->getMessage();
            $this->offerRead = false;

            return false;
        }
    }
}
