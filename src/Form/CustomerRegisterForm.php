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

use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Validator\Constraints;
use Symfony\Component\Validator\Constraints\PasswordStrength;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;
use Thelia\Model\Base\CustomerQuery;
use Thelia\Model\ConfigQuery;

class CustomerRegisterForm extends BaseForm
{
    protected function buildForm(): void
    {
        $this->formBuilder
          ->add('email', EmailType::class, [
              'constraints' => [
                  new Constraints\NotBlank(),
                  new Constraints\Email(),
                  new Constraints\Callback(
                      [$this, 'verifyExistingEmail']
                  ),
              ],
              'label' => Translator::getInstance()->trans('Email Address'),
              'label_attr' => [
                  'for' => 'email',
              ],
          ]);

        // confirm email
        if ((int) ConfigQuery::read('customer_confirm_email', 0)) {
            $this->formBuilder->add('email_confirm', EmailType::class, [
                'constraints' => [
                    new Constraints\NotBlank(),
                    new Constraints\Email(),
                    new Constraints\Callback([$this, 'verifyEmailField']),
                ],
                'label' => Translator::getInstance()->trans('Confirm Email Address'),
                'label_attr' => [
                    'for' => 'email_confirm',
                ],
            ]);
        }

        $this->formBuilder
          ->add('password', PasswordType::class, [
              'constraints' => [
                  new PasswordStrength([
                      'minScore' => 1,
                  ]),
              ],
              'label' => Translator::getInstance()->trans('Password'),
              'label_attr' => [
                  'for' => 'password',
              ],
              'attr' => [
                  'password_control' => true,
              ],
          ])
          ->add('password_confirm', PasswordType::class, [
              'constraints' => [
                  new Constraints\Callback([$this, 'verifyPasswordField']),
              ],
              'label' => Translator::getInstance()->trans('Password confirmation'),
              'label_attr' => [
                  'for' => 'password_confirmation',
              ],
          ]);
    }

    protected function getLocale(): ?string
    {
        $session = $this->request?->getSession();

        return $session?->getLang()?->getLocale();
    }

    public function verifyExistingEmail($value, ExecutionContextInterface $context): void
    {
        $customer = CustomerQuery::create()->findOneByEmail($value);
        if ($customer) {
            $context->addViolation(Translator::getInstance()->trans('This email is already used'));
        }
    }

    public function verifyEmailField($value,  ExecutionContextInterface $context): void
    {
        $data = $context->getRoot()->getData();

        if (isset($data['email_confirm']) && $data['email'] != $data['email_confirm']) {
            $context->addViolation(
                Translator::getInstance()->trans('email confirmation is not the same as email field')
            );
        }
    }

    public function verifyPasswordField($value, ExecutionContextInterface $context): void
    {
        $data = $context->getRoot()->getData();

        if ($data['password'] != $data['password_confirm']) {
            $context->addViolation(Translator::getInstance()->trans('password confirmation is not the same as password field'));
        }
    }
}
