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

namespace FlexyBundle\Components\Organisms\GiftWrapping;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Checkout\Exception\GiftMessageTooLongException;
use Thelia\Domain\Checkout\Exception\UnknownGiftWrappingException;
use Thelia\Domain\Checkout\Service\GiftWrappingProvider;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Taxation\TaxEngine\TaxEngine;
use Thelia\Model\GiftWrapping;

/**
 * The gift block of the checkout: the wrappings the shop offers, in an exclusive choice,
 * and the note for whoever receives the parcel.
 *
 * Both are written straight onto the cart, so what the summary prices and what the order
 * freezes is what the server holds, not what the browser believes. Only an identifier
 * and a piece of text ever travel: the price and the tax rule are read off the wrapping.
 *
 * The block renders nothing at all when the shop has no active wrapping — no heading, no
 * empty card — so a shop that does not offer the service has the checkout it always had.
 */
#[AsLiveComponent]
class Base
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    /**
     * The tag this block's title is written with, for the same reason the delivery and
     * payment steps take one: a page stacking the whole tunnel must not state three h1.
     */
    #[LiveProp]
    public string $headingLevel = 'h2';

    /**
     * The wrapping picked, or null for none. Refilled from the cart on mount and after
     * every choice, the way the payment step refills its module id.
     */
    #[LiveProp]
    public ?int $giftWrappingId = null;

    /**
     * The note being typed.
     *
     * Writable so the textarea can bind to it, and persisted by onUpdated rather than by
     * a submit button: the buyer has no reason to press anything, and a note left in the
     * field of an unsubmitted form would be lost at the next morph.
     */
    #[LiveProp(writable: true, onUpdated: 'onGiftMessageUpdated')]
    public string $giftMessage = '';

    /**
     * Why the last note was refused, when it was. Held as a prop because it has to
     * survive the re-render that follows the refusal.
     */
    #[LiveProp]
    public ?string $messageError = null;

    public function __construct(
        private readonly CartFacade $cartFacade,
        private readonly GiftWrappingProvider $giftWrappingProvider,
        private readonly LangService $langService,
        private readonly TaxEngine $taxEngine,
        // The `translator` service is the one Twig's |trans uses and the only one carrying
        // the theme catalogue; TranslatorInterface autowires to Thelia's own Translator.
        #[Autowire(service: 'translator')]
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function mount(): void
    {
        $cart = $this->cartFacade->getOrCreateFromSession();

        // A wrapping the merchant turned off while this cart was sitting there is dropped
        // before anything reads it, so the summary never prices a service that is no
        // longer on sale and the order never invoices one.
        $this->cartFacade->dropGiftWrappingThatIsNoLongerOffered($cart);

        $this->giftWrappingId = null === $cart->getGiftWrappingId() ? null : (int) $cart->getGiftWrappingId();
        $this->giftMessage = (string) $cart->getGiftMessage();
    }

    /**
     * Whether the shop offers the service at all. The template asks this first and renders
     * nothing when the answer is no.
     */
    public function isOffered(): bool
    {
        return $this->giftWrappingProvider->isOffered();
    }

    public function getMaximumMessageLength(): int
    {
        return GiftWrapping::MAX_GIFT_MESSAGE_LENGTH;
    }

    /**
     * The wrappings to show, in the order the merchant put them in, each with the price
     * the buyer would pay for it.
     *
     * The wording comes out as plain text and is printed escaped, for the reason the
     * consent wording is: it is written in the back office and shown on the payment page.
     *
     * @return list<array{id: int, title: string, description: string, taxed_price: float, free: bool, chosen: bool}>
     */
    public function getWrappings(): array
    {
        $locale = (string) $this->langService->getLocale();
        $country = $this->taxEngine->getDeliveryCountry();
        $state = $this->taxEngine->getDeliveryState();

        $wrappings = [];

        foreach ($this->giftWrappingProvider->activeGiftWrappings() as $giftWrapping) {
            $id = (int) $giftWrapping->getId();

            $wrappings[] = [
                'id' => $id,
                'title' => $this->giftWrappingProvider->title($giftWrapping, $locale),
                'description' => $this->giftWrappingProvider->description($giftWrapping, $locale),
                'taxed_price' => $this->giftWrappingProvider->taxedPrice($giftWrapping, $country, $state),
                'free' => $giftWrapping->isFree(),
                'chosen' => $id === $this->giftWrappingId,
            ];
        }

        return $wrappings;
    }

    /**
     * Records the choice and asks the summary to price it again.
     *
     * The identifier to store is passed rather than toggled, so the same call arriving
     * twice — the change event of a radio and of the label wrapped around it — writes the
     * same answer instead of undoing itself. Zero stands for "no wrapping": a radio group
     * needs a value for that option, and null does not survive the round trip as one.
     */
    #[LiveAction]
    public function chooseWrapping(#[LiveArg] int $giftWrappingId): void
    {
        $chosenId = 0 === $giftWrappingId ? null : $giftWrappingId;

        try {
            $this->cartFacade->chooseGiftWrapping($this->cartFacade->getOrCreateFromSession(), $chosenId);
        } catch (UnknownGiftWrappingException) {
            // Turned off between the render and the click. Nothing is charged, the list
            // rerenders without it, and saying so would only point at a service the shop
            // has stopped selling.
            $chosenId = null;
        }

        $this->giftWrappingId = $chosenId;

        $this->emit('syncSummary');
    }

    /**
     * Persists the note as it is typed, and keeps the refusal on screen when it is too long.
     *
     * The server is what refuses: the browser counts down the characters left as a
     * courtesy, and a note that gets past it is still refused here rather than cut.
     */
    public function onGiftMessageUpdated(): void
    {
        $this->messageError = null;

        try {
            $this->cartFacade->writeGiftMessage($this->cartFacade->getOrCreateFromSession(), $this->giftMessage);
        } catch (GiftMessageTooLongException $tooLong) {
            $this->messageError = $this->translator->trans(
                'The message must be %max characters at most.',
                ['%max' => $tooLong->maximumLength],
            );
        }
    }
}
