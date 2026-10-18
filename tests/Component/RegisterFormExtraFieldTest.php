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

namespace FlexyBundle\Tests\Component;

use FlexyBundle\Form\CustomerRegisterForm;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\TheliaFormEvent;
use Thelia\Test\IntegrationTestCase;

/**
 * A module extends the sign-up form by adding a field when the form is built
 * (TheliaEvents::FORM_AFTER_BUILD.'.'.form name). The page renders that field inside the
 * form and above the submit button, where the visitor fills it in before sending.
 *
 * Boots the host kernel on the test database `bin/test-prepare` creates.
 */
final class RegisterFormExtraFieldTest extends IntegrationTestCase
{
    private const string FIELD = 'module_extra_field';

    public function testAFieldAddedByAModuleIsRenderedAboveTheSubmitButton(): void
    {
        $this->dispatcher()->addListener(
            TheliaEvents::FORM_AFTER_BUILD.'.'.CustomerRegisterForm::getName(),
            static function (TheliaFormEvent $event): void {
                $event->getForm()->getFormBuilder()->add(self::FIELD, TextType::class, ['required' => false]);
            },
        );

        $html = $this->registerPage();

        $fieldPosition = strpos($html, 'name="'.CustomerRegisterForm::getName().'['.self::FIELD.']"');
        self::assertNotFalse($fieldPosition, 'the field the module added is on the page');

        $submitPosition = strpos($html, 'type="submit"', $fieldPosition);
        self::assertNotFalse($submitPosition, 'the submit button comes after the field');
        self::assertLessThan(strpos($html, '</form>', $fieldPosition), $submitPosition, 'both are in the same form');
    }

    public function testThePageIsUnchangedWithoutAnExtension(): void
    {
        self::assertStringNotContainsString(self::FIELD, $this->registerPage());
    }

    private function registerPage(): string
    {
        $request = Request::create('/customer/register');
        $request->setSession(static::getContainer()->get('request_stack')->getMainRequest()?->getSession());

        $response = static::$kernel->handle($request, HttpKernelInterface::SUB_REQUEST);
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getContent();
    }

    private function dispatcher(): EventDispatcherInterface
    {
        return static::getContainer()->get('event_dispatcher');
    }
}
