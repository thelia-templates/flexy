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

namespace FlexyBundle\Components\Forms\GuestCheckout;

use FlexyBundle\Form\GuestCheckoutForm;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Core\Form\FormServiceInterface;

/**
 * The "order without an account" form.
 *
 * Live for the same reason the registration form is: the state field is rebuilt from the
 * country, the legal identifiers appear with a company name, and the billing block only
 * shows once the buyer says the two addresses differ. Everything it decides is decided
 * again on the server — the form that reaches the controller holds exactly the fields the
 * buyer was asked for, and a forged post gains nothing.
 */
#[AsLiveComponent]
class Base
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    public function __construct(
        private readonly FormServiceInterface $formService,
    ) {
    }

    protected function instantiateForm(): FormInterface
    {
        return $this->formService->getFormByName(GuestCheckoutForm::FORM_NAME);
    }
}
