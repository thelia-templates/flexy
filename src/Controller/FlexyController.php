<?php

namespace FlexyBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Core\HttpKernel\Exception\RedirectException;

class FlexyController extends AbstractController
{
  public function __construct(public SecurityContext $securityContext, public TemplateHelperInterface $templateHelper, public ParserResolver $parserResolver, protected bool $useFallbackTemplate = true) {}

  public function customerIsLogged(): mixed
  {
    return $this->securityContext->hasCustomerUser();
  }

  public function checkAuth()
  {
    if ($this->customerIsLogged() == false) {
      throw new RedirectException('/customer');
    }
  }

  /**
   * @param TemplateDefinition $template the template to process, or null for using the front template
   *
   * @return ParserInterface the Thelia parser²
   */
  protected function getParser($template = null)
  {
    $path = $this->templateHelper->getActiveFrontTemplate()->getAbsolutePath();
    $parser = $this->parserResolver->getParser($path, $template);

    // Define the template that should be used
    $parser->setTemplateDefinition(
      $template ?: $this->templateHelper->getActiveFrontTemplate(),
      $this->useFallbackTemplate
    );

    return $parser;
  }

  protected function render($templateName, $args = [], $status = 200): Response
  {
    return new Response($this->renderRaw($templateName, $args), $status);
  }

  protected function renderRaw($templateName, $args = [])
  {
    return $this->getParser()->render($templateName, $args);
  }
}
