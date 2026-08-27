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

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
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

    public function checkAuth(): void
    {
        if (!$this->securityService->isAuthenticatedFront()) {
            throw new RedirectException($this->generateUrl('customer_login'));
        }
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
        return $this->getParser()->render($templateName, $args);
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
