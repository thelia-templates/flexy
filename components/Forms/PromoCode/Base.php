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

namespace FlexyBundle\Components\Forms\PromoCode;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Core\Event\Coupon\CouponConsumeEvent;
use Thelia\Core\Event\DefaultActionEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Condition\Exception\InvalidConditionException;
use Thelia\Condition\Exception\UnmatchableConditionException;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Promotion\Coupon\Exception\CouponExpiredException;
use Thelia\Domain\Promotion\Coupon\Exception\CouponNotReleaseException;
use Thelia\Domain\Promotion\Coupon\Exception\CouponNoUsageLeftException;
use Thelia\Domain\Promotion\Coupon\Exception\InactiveCouponException;
use Thelia\Domain\Quote\Cart\QuoteCartLockedException;
use Thelia\Form\CouponCode;

#[AsLiveComponent]
class Base
{
    use ComponentToolsTrait;
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    // A FormError added after submitForm() would be lost: the form is rehydrated from the props.
    #[LiveProp]
    public ?string $error = null;

    public function __construct(
        private readonly FormServiceInterface $formService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly CartFacade $cartFacade,
        // The `translator` service is the one Twig's |trans uses and the only one carrying the
        // theme catalogue; TranslatorInterface autowires to Thelia's own Translator, which does not.
        #[Autowire(service: 'translator')]
        private readonly TranslatorInterface $translator,
    ) {
    }

    protected function instantiateForm(): FormInterface
    {
        $form = $this->formService->getFormByName(CouponCode::getName());

        $form->add('submit', SubmitType::class, [
            'label' => $this->translator->trans('Apply'),
        ]);

        return $form;
    }

    private function getDataModelValue(): ?string
    {
        return 'norender|*';
    }

    #[LiveAction]
    public function save(): void
    {
        $this->error = null;
        $this->submitForm();

        $couponCode = $this->getForm()->get('coupon-code')->getData();

        // The core form only checks the code exists, not that it is usable, and the refusals it
        // raises have no shared parent nor HTTP mapping — they answered 500.
        try {
            $this->eventDispatcher->dispatch(new CouponConsumeEvent($couponCode), TheliaEvents::COUPON_CONSUME);
        } catch (InactiveCouponException|CouponExpiredException|CouponNotReleaseException|CouponNoUsageLeftException|UnmatchableConditionException|InvalidConditionException) {
            // One message for all six: naming the reason would confirm the code exists.
            $this->error = $this->translator->trans('This promo code cannot be used.');

            return;
        } catch (QuoteCartLockedException $exception) {
            // The cart of an accepted quote holds the discount of the quote: a code has no place on it, and saying so
            // reveals nothing about the code.
            $this->error = $this->translator->trans($exception->getMessage());

            return;
        }

        $this->cartFacade->recalculatePostage($this->cartFacade->getOrCreateFromSession());

        $this->emit('syncSummary');
    }

    #[LiveListener('removeCoupon')]
    public function removeCoupon(#[LiveArg] ?string $code): void
    {
        $this->error = null;
        $this->eventDispatcher->dispatch(new DefaultActionEvent(), TheliaEvents::COUPON_CLEAR_ALL);

        $this->cartFacade->recalculatePostage($this->cartFacade->getOrCreateFromSession());

        $this->emit('syncSummary');
    }
}
