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
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PasswordStrength;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Domain\Customer\Service\CustomerRegistrationLimiter;
use Thelia\Form\BaseForm;
use Thelia\Model\Base\CustomerQuery;

class CustomerRegisterForm extends BaseForm
{
    public function __construct(
        #[Autowire(service: 'translator')]
        protected readonly TranslatorInterface $translation,
    ) {
    }

    private ?CustomerRegistrationLimiter $registrationLimiter = null;

    // A setter, not a constructor argument: the update form extends this one and calls the
    // parent constructor, and it has no registration to count. Only the container calls it:
    // a form built any other way (new, by the form factory's fallback) has no limiter, and
    // verifyExistingEmail() refuses to validate rather than let attempts through uncounted.
    #[Required]
    public function setRegistrationLimiter(CustomerRegistrationLimiter $registrationLimiter): void
    {
        $this->registrationLimiter = $registrationLimiter;
    }

    protected function buildForm(): void
    {
        $this->formBuilder->add('firstname', TextType::class, [
            'constraints' => [
                new NotBlank(),
            ],
            'label' => Translator::getInstance()->trans('Firstname'),
            'label_attr' => [
                'for' => 'firstname',
            ],
        ]);

        $this->formBuilder->add('lastname', TextType::class, [
            'constraints' => [
                new NotBlank(),
            ],
            'label' => Translator::getInstance()->trans('Lastname'),
            'label_attr' => [
                'for' => 'lastname',
            ],
        ]);

        $this->formBuilder
            ->add('email', EmailType::class, [
                'constraints' => [
                    new Constraints\NotBlank(),
                    new Constraints\Email(),
                    new Constraints\Callback(
                        [$this, 'verifyExistingEmail']
                    ),
                ],
                'label' => Translator::getInstance()->trans('Email'),
                'label_attr' => [
                    'for' => 'email',
                ],
            ]);

        $this->formBuilder->add('password', PasswordType::class, [
            'constraints' => [
                new NotBlank(),
                new PasswordStrength(minScore: 1),
            ],
            'label' => Translator::getInstance()->trans('Password'),
            'label_attr' => [
                'for' => 'password',
            ],
            'attr' => [
                'password_control' => true,
            ],
        ]);
    }

    public function verifyExistingEmail($value, ExecutionContextInterface $context): void
    {
        // An empty address names no account: nothing to probe, nothing to spend.
        if (null === $value || '' === trim((string) $value)) {
            return;
        }

        // Spent before the address is looked up, whether or not it names an account, and
        // answered without a word about the address: a caller over budget must not be
        // able to read from this form which addresses are registered.
        if (null === $this->registrationLimiter) {
            throw new \LogicException(\sprintf('%s needs its registration limiter to validate an address: build the form through the container, not with "new".', static::class));
        }

        if (!$this->registrationLimiter->allows((string) $value)) {
            $context->addViolation($this->translation->trans('Too many attempts. Please try again later.'));

            return;
        }

        $customer = CustomerQuery::create()->findOneByEmail($value);
        if ($customer) {
            $context->addViolation($this->translation->trans('This email is already used'));
        }
    }

    public static function getName(): string
    {
        return 'flexybundle_form_customer_register_form';
    }
}
