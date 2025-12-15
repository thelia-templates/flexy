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

namespace FlexyBundle\UiComponents\Checkout\PromoCodeForm;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Event\Coupon\CouponConsumeEvent;
use Thelia\Core\Event\Coupon\CouponDeleteEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Form\CouponCode;
use TwigEngine\Service\FormService;

#[AsLiveComponent(name: 'Flexy:Checkout:PromoCodeForm', template: '@UiComponents/Checkout/PromoCodeForm/PromoCodeForm.html.twig')]
class PromoCodeForm extends BaseFrontController
{
    use ComponentWithFormTrait;
    use ComponentToolsTrait;
    use DefaultActionTrait;

    public function __construct(
        private readonly FormService $formService,
        private readonly FormFactoryInterface $formFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly CartFacade $cartFacade,
        #[Autowire(service: 'translator')]
        public TranslatorInterface $translator,
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

    #[LiveAction]
    public function save(): void
    {
        $this->submitForm();

        $couponCode = $this->getForm()->get('coupon-code')->getData();
        $this->eventDispatcher->dispatch(new CouponConsumeEvent($couponCode), TheliaEvents::COUPON_CONSUME);

        $this->cartFacade->recalculatePostage($this->cartFacade->getOrCreateFromSession());

        $this->emit('syncSummary');
    }


    #[LiveListener('removeCoupon')]
    public function removeCoupon(#[LiveArg] ?string $code): void
    {
        $this->eventDispatcher->dispatch(new CouponDeleteEvent($code), TheliaEvents::COUPON_DELETE);

        $this->cartFacade->recalculatePostage($this->cartFacade->getOrCreateFromSession());

        $this->emit('syncSummary');
    }
}
