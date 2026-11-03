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

namespace FlexyBundle\Controller;

use FlexyBundle\Form\CustomerRegisterForm;
use FlexyBundle\Service\GuestCheckoutGate;
use FlexyBundle\View\ViewName;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Controller\BaseController;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Core\Form\TheliaFormValidator;
use Thelia\Core\HttpKernel\Exception\RedirectException;
use Thelia\Core\Security\Front\FrontSecurityServiceInterface;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Template\ParserContext;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Domain\Customer\DTO\CustomerRegisterDTO;
use Thelia\Domain\Customer\Exception\InvalidRegistrationTokenException;
use Thelia\Domain\Customer\Service\AuthenticationReturnUrl;
use Thelia\Domain\Customer\Service\CustomerAuthenticator;
use Thelia\Domain\Customer\Service\CustomerRegistrationService;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Form\Exception\FormValidationException;

class FlexyController extends BaseController
{
    public const EMPTY_FORM_NAME = 'thelia.empty';
    public const CONTROLLER_TYPE = 'front';

    /**
     * The front-office routes live in the default router: this theme declares them with
     * #[Route], and so do the modules. Only the back office runs a router of its own.
     */
    protected string $currentRouter = 'router';

    public function __construct(
        public SecurityContext $securityContext,
        public ParserContext $parserContext,
        public TemplateHelperInterface $templateHelper,
        public ParserResolver $parserResolver,
        public TheliaFormValidator $theliaFormValidator,
        public RequestStack $requestStack,
        #[Autowire(service: 'translator')]
        public TranslatorInterface $translator,
        public TheliaFormFactory $theliaFormFactory,
        protected readonly FrontSecurityServiceInterface $securityService,
        protected readonly RouterInterface $router,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function getControllerType(): string
    {
        return self::CONTROLLER_TYPE;
    }

    /**
     * Guards everything an account gives access to.
     *
     * A guest checking out sits in the session under the same key as a signed-in
     * customer, because the checkout builds the order from it. Asking only whether a
     * customer is there would therefore hand them the account pages, someone else's
     * orders included: they proved nothing about the address they typed, so they are
     * sent to sign in like any other visitor. The checkout steps that are open to them
     * ask GuestCheckoutGate instead.
     */
    public function checkAuth(): void
    {
        if (!$this->securityContext->hasAuthenticatedCustomerUser()) {
            // The guarded page travels with the redirection: signing in is a detour, and
            // dropping the visitor on their account afterwards leaves them to find their
            // own way back to the checkout step or the order they had asked for.
            throw new RedirectException($this->generateUrl('customer_login', [
                AuthenticationReturnUrl::PARAMETER => $this->getRequest()->getRequestUri(),
            ]));
        }
    }

    /**
     * The sign-up page, for the standard form and for the link of a customer group alike.
     *
     * Without the detour, a logged-in visitor creates a second, orphaned Customer row and the
     * informations step then writes onto their original account. A registration under
     * way is the exception: its first step signs the visitor in, so coming back to this
     * page (a reload, the stepper) belongs to the step that follows.
     *
     * @param array<string, mixed> $args what the page needs beyond the return URL
     */
    protected function renderRegistrationPage(AuthenticationReturnUrl $authenticationReturnUrl, SessionInterface $session, array $args = []): Response
    {
        $authenticationReturnUrl->capture();

        if ($this->getSecurityContext()->hasAuthenticatedCustomerUser()) {
            return $this->generateRedirect($this->generateUrl(
                null !== $session->get('registration_customer_id') ? 'customer_informations' : 'account_index'
            ));
        }

        // Handed to the form so that its POST carries the destination too: a cached
        // registration page would not have run this controller to remember it.
        return $this->render('register', ['returnUrl' => $authenticationReturnUrl->requested()] + $args);
    }

    /**
     * Registers the account the sign-up form describes, for the standard form (no token) and
     * for the link of a customer group (its token), and comes back to $errorUrl when the form
     * does not validate.
     */
    protected function registerCustomerFromForm(
        CustomerRegistrationService $customerRegistrationProcessor,
        SessionInterface $session,
        LangService $langService,
        CustomerAuthenticator $customerLoginProcessor,
        GuestCheckoutGate $guestCheckoutGate,
        AuthenticationReturnUrl $authenticationReturnUrl,
        ?string $registrationToken,
        string $errorUrl,
    ): RedirectResponse {
        $form = $this->createForm(CustomerRegisterForm::class);

        try {
            $formValidated = $this->validateForm($form, Request::METHOD_POST);

            try {
                $customer = $customerRegistrationProcessor->registerCustomer(new CustomerRegisterDTO(
                    firstname: $formValidated->get('firstname')->getData(),
                    lastname: $formValidated->get('lastname')->getData(),
                    email: $formValidated->get('email')->getData(),
                    password: $formValidated->get('password')->getData(),
                    // The language the visitor registered in is only known here. Left out, the
                    // account keeps a NULL lang_id and every later message for that customer
                    // goes out in the shop default instead.
                    langId: $langService->getLang()?->getId(),
                ), $registrationToken);
            } catch (InvalidRegistrationTokenException) {
                // The link of the group was closed between the check of the caller and the write.
                throw new NotFoundHttpException();
            }

            $session->set('registration_customer_id', $customer->getId());
            $authenticationReturnUrl->capture();

            // A shop that does not confirm email addresses has nothing left to check, so
            // the visitor is signed in right away: the step that follows edits their own
            // address as an authenticated customer, and the registration ends on the page
            // they came from instead of a sign-in form they have just filled in. An account
            // waiting for its activation code stays signed out, as the code is the check.
            if ($customer->getEnable()) {
                $customerLoginProcessor->processLogin($customer);
                $guestCheckoutGate->markAsAuthenticated();
            }

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $e) {
            $message = $this->getTranslator()->trans('Please check your input: %s', ['%s' => $e->getMessage()]);
        }

        $form->setErrorMessage($message);
        $this->getParserContext()->addForm($form);

        if ($form->hasErrorUrl()) {
            return $this->generateErrorRedirect($form);
        }

        return $this->generateRedirect($errorUrl);
    }

    protected function generateUrl(string $route, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        return $this->router->generate($route, $parameters, $referenceType);
    }

    protected function render(string $templateName, array $args = [], int $status = 200): Response
    {
        return new Response($this->renderRaw($templateName, $args), $status);
    }

    protected function renderRaw(string $templateName, array $args = [], string|TemplateDefinition|null $templateDir = null): string
    {
        $this->nameTheView($templateName);

        return $this->getParser()->render($templateName, $args);
    }

    /**
     * The core sets `_view` on the pages it routes itself, and the layout calls a theme hook
     * named after it. A page rendered here has none, so it takes its template's name; one
     * the request already carries (a rewritten URL) is kept.
     */
    private function nameTheView(string $templateName): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request || '' !== (string) $request->attributes->get('_view', '')) {
            return;
        }

        $request->attributes->set('_view', ViewName::fromTemplate($templateName));
    }

    protected function getParser(?string $template = null)
    {
        $path = $this->getTemplateHelper()->getActiveFrontTemplate()->getAbsolutePath();
        $parser = $this->parserResolver->getParser($path, $template);

        $parser->setTemplateDefinition(
            $template ?: $this->getTemplateHelper()->getActiveFrontTemplate(),
            $this->useFallbackTemplate
        );

        return $parser;
    }
}
