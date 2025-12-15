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
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Translation\Translator;
use Thelia\Model\AddressQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Module\BaseModule;

class CheckoutForm extends AbstractType
{
    public function __construct(
        private readonly SecurityContext $security,
        #[Autowire(service: 'translator')]
        public ?TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('delivery-address-id', HiddenType::class, [
                'required' => true,
                'constraints' => [
                    new Constraints\NotBlank(),
                    new Constraints\Callback([$this, 'verifyDeliveryAddress']),
                ],
            ])
            ->add('delivery-module-id', HiddenType::class, [
                'required' => true,
                'constraints' => [
                    new Constraints\NotBlank(),
                    new Constraints\Callback([$this, 'verifyDeliveryModule']),
                ],
            ])
            ->add('invoice-address-id', HiddenType::class, [
                'required' => true,
                'constraints' => [
                    new Constraints\NotBlank(),
                    new Constraints\Callback([$this, 'verifyInvoiceAddress']),
                ],
            ])
            ->add('payment-module-id', HiddenType::class, [
                'required' => true,
                'constraints' => [
                    new Constraints\NotBlank(),
                    new Constraints\Callback([$this, 'verifyPaymentModule']),
                ],
            ]);
    }

    public function verifyDeliveryAddress($value, ExecutionContextInterface $context): void
    {
        $address = AddressQuery::create()->findPk($value);

        if (null === $address) {
            $context->addViolation($this->translator->trans('Address ID not found'));

            return;
        }

        $customer = $this->security->getCustomerUser();
        if (!$customer || $address->getCustomerId() !== $customer->getId()) {
            $context->addViolation($this->translator->trans('Unauthorized address access'));
        }
    }

    public function verifyInvoiceAddress($value, ExecutionContextInterface $context): void
    {
        $address = AddressQuery::create()->findPk($value);

        if (null === $address) {
            $context->addViolation($this->translator->trans('Address ID not found'));

            return;
        }

        $customer = $this->security->getCustomerUser();
        if (!$customer || $address->getCustomerId() !== $customer->getId()) {
            $context->addViolation($this->translator->trans('Unauthorized address access'));
        }
    }

    public function verifyDeliveryModule($value, ExecutionContextInterface $context): void
    {
        $module = ModuleQuery::create()
            ->filterActivatedByTypeAndId(BaseModule::DELIVERY_MODULE_TYPE, $value)
            ->findOne();

        if (null === $module) {
            $context->addViolation($this->translator->trans('Delivery module ID not found'));
        } elseif (!$module->isDeliveryModule()) {
            $context->addViolation(
                \sprintf($this->translator->trans("delivery module %s is not a Thelia\Module\DeliveryModuleInterface"), $module->getCode())
            );
        }
    }

    public function verifyPaymentModule($value, ExecutionContextInterface $context): void
    {
        $module = ModuleQuery::create()
            ->filterActivatedByTypeAndId(BaseModule::PAYMENT_MODULE_TYPE, $value)
            ->findOne();

        if (null === $module) {
            $context->addViolation($this->translator->trans('Payment module ID not found'));
        } elseif (!$module->isPayementModule()) {
            $context->addViolation(
                \sprintf($this->translator->trans("payment module %s is not a Thelia\Module\PaymentModuleInterface"), $module->getCode())
            );
        }
    }
}
