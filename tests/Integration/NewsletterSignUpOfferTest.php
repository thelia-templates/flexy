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

namespace FlexyBundle\Tests\Integration;

use FlexyBundle\Form\CustomerInformationsForm;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Model\ConfigQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The pages that offer a newsletter sign-up follow the switch of the shop: the box of the
 * second registration step and the benefits listed on the registration page. Switched on (the
 * default), nothing changes.
 */
final class NewsletterSignUpOfferTest extends IntegrationTestCase
{
    private const string WITH_NEWSLETTER = 'receive the newsletter';

    protected function tearDown(): void
    {
        ConfigQuery::write('newsletter_enabled', '1');
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testTheInformationsFormHasTheNewsletterBoxByDefault(): void
    {
        self::assertTrue($this->informationsFormHasNewsletterField());
    }

    public function testTheInformationsFormHasNoNewsletterBoxWhenTheNewsletterIsOff(): void
    {
        ConfigQuery::write('newsletter_enabled', '0');

        self::assertFalse($this->informationsFormHasNewsletterField());
    }

    public function testTheRegistrationPageListsTheNewsletterAmongTheBenefitsByDefault(): void
    {
        self::assertStringContainsString(self::WITH_NEWSLETTER, $this->registerPage());
    }

    public function testTheRegistrationPageDoesNotListTheNewsletterWhenItIsOff(): void
    {
        ConfigQuery::write('newsletter_enabled', '0');

        $html = $this->registerPage();

        self::assertStringNotContainsString(self::WITH_NEWSLETTER, $html);
        self::assertStringContainsString('manage your wish lists', $html);
    }

    private function informationsFormHasNewsletterField(): bool
    {
        return $this->getService(TheliaFormFactory::class)
            ->createForm(CustomerInformationsForm::class, FormType::class, [], ['csrf_protection' => false])
            ->getForm()
            ->has('newsletter');
    }

    private function registerPage(): string
    {
        $request = Request::create('/customer/register');
        $request->setSession(static::getContainer()->get('request_stack')->getMainRequest()?->getSession());

        $response = static::$kernel->handle($request, HttpKernelInterface::SUB_REQUEST);
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getContent();
    }
}
