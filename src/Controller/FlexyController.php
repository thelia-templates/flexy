<?php

namespace FlexyBundle\Controller;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Core\Form\TheliaFormValidator;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Core\HttpKernel\Exception\RedirectException;
use Thelia\Core\Template\ParserContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Controller\BaseController;

class FlexyController extends BaseController
{
  public const EMPTY_FORM_NAME = 'thelia.empty';
  public const CONTROLLER_TYPE = 'front';

  protected string $currentRouter = 'router.front';

  public function getControllerType(): string
  {
    return self::CONTROLLER_TYPE;
  }
  public function __construct(
    public SecurityContext $securityContext,
    public ParserContext $parserContext,
    public TemplateHelperInterface $templateHelper,
    public ParserResolver $parserResolver,
    public TheliaFormValidator $theliaFormValidator,
    public RequestStack $requestStack,
    public TranslatorInterface $translator,
    public TheliaFormFactory $theliaFormFactory,
  ) {}

  public function customerIsLogged()
  {
    return $this->getSecurityContext()->hasCustomerUser();
  }

  public function checkAuth()
  {
    if ($this->customerIsLogged() == false) {
      throw new RedirectException($this->generateUrl('customer_login'));
    }
  }

  protected function generateUrl(string $route, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string
  {
    return $this->container->get('router')->generate($route, $parameters, $referenceType);
  }

  /**
   * @param TemplateDefinition $template the template to process, or null for using the front template
   *
   * @return ParserInterface the Thelia parser²
   */
  protected function getParser($template = null)
  {
    $path = $this->getTemplateHelper()->getActiveFrontTemplate()->getAbsolutePath();
    $parser = $this->parserResolver->getParser($path, $template);

    // Define the template that should be used
    $parser->setTemplateDefinition(
      $template ?: $this->getTemplateHelper()->getActiveFrontTemplate(),
      $this->useFallbackTemplate
    );

    return $parser;
  }

  /**
   * Render the given template, and returns the result as an Http Response.
   *
   * @param string $templateName the complete template name, with extension
   * @param array  $args         the template arguments
   * @param int    $status       http code status
   *
   * @return \Thelia\Core\HttpFoundation\Response
   */
  protected function render($templateName, $args = [], $status = 200)
  {
    return new Response($this->renderRaw($templateName, $args), $status);
  }

  /**
   * Render the given template, and returns the result as a string.
   *
   * @param string $templateName the complete template name, with extension
   * @param array  $args         the template arguments
   * @param string $templateDir
   *
   * @return string
   */
  protected function renderRaw($templateName, $args = [], $templateDir = null)
  {
    // Render the template.
    return $this->getParser()->render($templateName, $args);
  }
}
