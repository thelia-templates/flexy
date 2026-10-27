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

namespace FlexyBundle\Tests\Unit;

use FlexyBundle\Components\Layouts\ProductListing\Base as ProductListing;
use FlexyBundle\Service\FormService;
use FlexyBundle\Service\ProductImageResolver;
use FlexyBundle\Service\ProductSearch;
use FlexyBundle\Service\ProductSort;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Yaml\Yaml;
use Thelia\Api\Service\DataAccess\DataAccessService;

/**
 * The filters of a category are a form on a page a reverse proxy may serve to every visitor.
 * A token tied to the session is the one of whoever asked first, so the next visitor is told
 * it is invalid on the first filter they apply: the token of that form is stateless, and
 * nothing here says so unless the id it carries is the one config/packages/csrf.yaml lists.
 */
final class ProductListingFilterTokenTest extends TestCase
{
    public function testTheFilterFormCarriesTheTokenIdThatIsKeptOutOfTheSession(): void
    {
        $form = $this->theFilterForm();

        self::assertSame(ProductListing::FORM_TOKEN_ID, $form->getConfig()->getOption('csrf_token_id'));
    }

    public function testThatTokenIdIsListedAsStateless(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 2).'/config/packages/csrf.yaml');

        self::assertContains(ProductListing::FORM_TOKEN_ID, $config['framework']['csrf_protection']['stateless_token_ids']);
    }

    /**
     * The services are final, and none of them is reached: the form of a listing with no
     * scope is built before any of them is asked for anything.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function withoutConstructor(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function theFilterForm(): FormInterface
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new CsrfExtension($this->createStub(CsrfTokenManagerInterface::class)))
            ->getFormFactory();

        $listing = new ProductListing(
            $this->withoutConstructor(DataAccessService::class),
            new RequestStack(),
            $this->withoutConstructor(FormService::class),
            $this->withoutConstructor(ProductImageResolver::class),
            $this->withoutConstructor(ProductSearch::class),
            $this->withoutConstructor(ProductSort::class),
        );

        $container = new Container();
        $container->set('form.factory', $factory);
        $listing->setContainer($container);

        $instantiate = new \ReflectionMethod($listing, 'instantiateForm');

        return $instantiate->invoke($listing);
    }
}
