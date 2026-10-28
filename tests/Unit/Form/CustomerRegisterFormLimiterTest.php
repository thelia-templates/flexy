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

namespace FlexyBundle\Tests\Unit\Form;

use FlexyBundle\Form\CustomerRegisterForm;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The registration limiter is the barrier against probing the registered addresses: a form
 * that was not given one (built with "new" by the fallback of the form factory) says so,
 * instead of failing on an uninitialized property or letting the attempt through uncounted.
 */
final class CustomerRegisterFormLimiterTest extends TestCase
{
    public function testAFormWithoutItsLimiterRefusesToValidateAnAddress(): void
    {
        $form = new CustomerRegisterForm($this->createStub(TranslatorInterface::class));
        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::never())->method('addViolation');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('CustomerRegisterForm needs its registration limiter to validate an address');

        $form->verifyExistingEmail('someone@example.test', $context);
    }

    public function testAnEmptyAddressNeedsNoLimiter(): void
    {
        $form = new CustomerRegisterForm($this->createStub(TranslatorInterface::class));
        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::never())->method('addViolation');

        $form->verifyExistingEmail('  ', $context);
    }
}
