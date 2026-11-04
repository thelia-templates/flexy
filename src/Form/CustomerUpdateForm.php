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

namespace FlexyBundle\Form;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Validator\Constraints;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\SecurityContext;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CustomerQuery;

class CustomerUpdateForm extends CustomerRegisterForm
{
    public const FORM_NAME = 'flexybundle_form_customer_update_form';

    public function __construct(
        #[Autowire(service: 'translator')]
        TranslatorInterface $translation,
        private readonly SecurityContext $security,
    ) {
        parent::__construct($translation);
    }

    protected function buildForm(): void
    {
        parent::buildForm();

        $this->formBuilder->remove('password');
        $this->formBuilder->remove('email');

        $canUpdateEmail = (bool) ConfigQuery::read('customer_change_email', 0);

        // When the shop forbids email changes the field is read-only and only shows the
        // current address. It must carry no constraint: Symfony validates a disabled
        // field too, and the customer has no way to fix a violation raised on it.
        $this->formBuilder->add('email', EmailType::class, [
            'constraints' => $canUpdateEmail ? [
                new Constraints\NotBlank(),
                new Constraints\Email(),
                new Constraints\Callback([$this, 'verifyExistingEmail']),
            ] : [],
            'required' => $canUpdateEmail,
            'disabled' => !$canUpdateEmail,
            'label' => $this->translation->trans('Email'),
            'label_attr' => [
                'for' => 'email',
            ],
            'help' => $canUpdateEmail ? null : $this->translation->trans('To change your email address, please contact us.'),
        ]);
    }

    /**
     * @param mixed $value
     */
    public function verifyExistingEmail($value, ExecutionContextInterface $context): void
    {
        $customer = CustomerQuery::create()->findOneByEmail($value);

        // The customer's own address is not a duplicate.
        if (null === $customer || $customer->getId() === $this->security->getCustomerUser()?->getId()) {
            return;
        }

        $context->addViolation($this->translation->trans('This email is already used'));
    }

    public static function getName(): string
    {
        return self::FORM_NAME;
    }
}
