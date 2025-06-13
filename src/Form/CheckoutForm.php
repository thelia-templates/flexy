<?php

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

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Translation\Translator;
use Thelia\Model\AddressQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Module\BaseModule;

class CheckoutForm extends AbstractType
{
    public function __construct(
        private readonly SecurityContext $security
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
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
            $context->addViolation(Translator::getInstance()->trans('Address ID not found'));
            return;
        }

        $customer = $this->security->getCustomerUser();
        if (!$customer || $address->getCustomerId() !== $customer->getId()) {
            $context->addViolation(Translator::getInstance()->trans('Unauthorized address access'));
        }
    }

    public function verifyInvoiceAddress($value, ExecutionContextInterface $context): void
    {
        $address = AddressQuery::create()->findPk($value);

        if (null === $address) {
            $context->addViolation(Translator::getInstance()->trans('Address ID not found'));
            return;
        }

        $customer = $this->security->getCustomerUser();
        if (!$customer || $address->getCustomerId() !== $customer->getId()) {
            $context->addViolation(Translator::getInstance()->trans('Unauthorized address access'));
        }
    }

    public function verifyDeliveryModule($value, ExecutionContextInterface $context): void
    {
        $module = ModuleQuery::create()
            ->filterActivatedByTypeAndId(BaseModule::DELIVERY_MODULE_TYPE, $value)
            ->findOne();

        if (null === $module) {
            $context->addViolation(Translator::getInstance()->trans('Delivery module ID not found'));
        } elseif (!$module->isDeliveryModule()) {
            $context->addViolation(
                sprintf(Translator::getInstance()->trans("delivery module %s is not a Thelia\Module\DeliveryModuleInterface"), $module->getCode())
            );
        }
    }

    public function verifyPaymentModule($value, ExecutionContextInterface $context): void
    {
        $module = ModuleQuery::create()
            ->filterActivatedByTypeAndId(BaseModule::PAYMENT_MODULE_TYPE, $value)
            ->findOne();

        if (null === $module) {
            $context->addViolation(Translator::getInstance()->trans('Payment module ID not found'));
        } elseif (!$module->isPayementModule()) {
            $context->addViolation(
                sprintf(Translator::getInstance()->trans("payment module %s is not a Thelia\Module\PaymentModuleInterface"), $module->getCode())
            );
        }
    }
}
