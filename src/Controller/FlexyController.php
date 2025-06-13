<?php

namespace FlexyBundle\Controller;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Controller\BaseController;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Core\Form\TheliaFormValidator;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\HttpKernel\Exception\RedirectException;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Template\ParserContext;
use Thelia\Core\Template\TemplateHelperInterface;
use TwigEngine\Service\SecurityService;

class FlexyController extends BaseController
{
    public const EMPTY_FORM_NAME = 'thelia.empty';
    public const CONTROLLER_TYPE = 'front';

    protected string $currentRouter = 'router.front';

    public function __construct(
        public SecurityContext           $securityContext,
        public ParserContext             $parserContext,
        public TemplateHelperInterface   $templateHelper,
        public ParserResolver            $parserResolver,
        public TheliaFormValidator       $theliaFormValidator,
        public RequestStack              $requestStack,
        public TranslatorInterface       $translator,
        public TheliaFormFactory         $theliaFormFactory,
        private readonly SecurityService $securityService,
    )
    {
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
        return $this->container->get('router')?->generate($route, $parameters, $referenceType);
    }

    protected function render($templateName, $args = [], $status = 200)
    {
        return new Response($this->renderRaw($templateName, $args), $status);
    }

    protected function renderRaw($templateName, $args = [], $templateDir = null)
    {
        return $this->getParser()->render($templateName, $args);
    }

    protected function getParser($template = null)
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
