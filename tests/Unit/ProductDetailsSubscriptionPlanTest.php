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

use FlexyBundle\Components\Layouts\ProductDetails\Base as ProductDetails;
use FlexyBundle\Service\RunningSaleResolver;
use PHPUnit\Framework\TestCase;
use Propel\Runtime\Collection\ObjectCollection;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\LiveResponder;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Api\Service\DataAccess\ProductSaleElementsAccessService;
use Thelia\Core\Form\FormServiceInterface;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Cart\DTO\CartItemAddDTO;
use Thelia\Domain\Cart\Exception\InvalidCartException;
use Thelia\Domain\Cart\Service\CartGiftWrappingService;
use Thelia\Domain\Cart\Service\CartItemService;
use Thelia\Domain\Cart\Service\CartRetriever;
use Thelia\Domain\Cart\Service\CartSelectionService;
use Thelia\Domain\Checkout\Service\GiftWrappingProvider;
use Thelia\Domain\Media\AltTextResolver;
use Thelia\Domain\Shipping\Service\PostageHandler;
use Thelia\Domain\Taxation\TaxEngine\TaxCalculatorFactoryInterface;
use Thelia\Model\Cart;
use Thelia\Model\CartItem;

/**
 * The plan the shopper picked on the product sheet travels with the add to the cart as `subscriptionPlanId`; a one-time
 * purchase carries none, and the refusal of the core's guard (mixed cart, guest...) is flagged with its own message.
 *
 * Needs the core's subscriptions (the DTO argument): skipped on a core that has none. What the component keeps is
 * nullable, so the properties are tested before they are read.
 */
final class ProductDetailsSubscriptionPlanTest extends TestCase
{
    private ?CartItemAddDTO $added = null;

    protected function setUp(): void
    {
        $this->added = null;

        try {
            class_exists(Cart::class);
        } catch (\Error) {
            self::markTestSkipped('The generated Propel models are not autoloaded.');
        }

        if (!class_exists('Thelia\\Domain\\Subscription\\SubscriptionStateMachine')) {
            self::markTestSkipped('The core has no subscriptions.');
        }
    }

    protected function tearDown(): void
    {
        $this->added = null;
    }

    public function testAPlanChoiceIsAddedToTheCartLine(): void
    {
        $component = $this->component(null);
        $component->selectedPlan = 7;

        $component->save();

        self::assertNotNull($this->added);
        self::assertSame(7, $this->added->toArray()['subscriptionPlanId']);
    }

    public function testAOneTimePurchaseCarriesNoPlan(): void
    {
        $component = $this->component(null);

        $component->save();

        self::assertNotNull($this->added);
        self::assertNull($this->added->toArray()['subscriptionPlanId']);
    }

    public function testTheRefusalOfTheGuardIsShownWithItsMessage(): void
    {
        $component = $this->component(new InvalidCartException('A subscription is bought on its own: empty your cart or order it first.'));
        $component->selectedPlan = 7;

        $component->save();

        self::assertTrue($component->cartRefused);
        self::assertSame('A subscription is bought on its own: empty your cart or order it first.', $component->cartRefusalReason);
    }

    public function testTheChoiceStartsOnTheOneTimePurchase(): void
    {
        self::assertSame(0, $this->component(null)->selectedPlan);
        self::assertSame([], $this->component(null)->subscriptionPlans);
    }

    private function component(?\Throwable $refusal): ProductDetails
    {
        $cartItems = self::createStub(CartItemService::class);
        $line = self::createStub(CartItem::class);
        $line->method('getId')->willReturn(100);
        $line->method('getQuantity')->willReturn(1.0);

        if (null === $refusal) {
            $cartItems->method('addItem')->willReturnCallback(function (CartItemAddDTO $dto) use ($line): CartItem {
                $this->added = $dto;

                return $line;
            });
        } else {
            $cartItems->method('addItem')->willThrowException($refusal);
        }

        $cartRetriever = self::createStub(CartRetriever::class);
        $cartRetriever->method('fromSessionOrCreateNew')->willReturn(self::createStub(Cart::class));
        $cartOfTheSession = self::createStub(Cart::class);
        $cartOfTheSession->method('getCartItems')->willReturn(new ObjectCollection([]));
        $cartRetriever->method('fromSession')->willReturn($cartOfTheSession);

        $formService = self::createStub(FormServiceInterface::class);
        $formService->method('getFormByName')->willReturnCallback(static function (): Form {
            $builder = Forms::createFormFactory()->createNamedBuilder('thelia_cart_add', FormType::class);

            foreach (['product', 'product_sale_elements_id', 'quantity', 'append', 'newness'] as $field) {
                $builder->add($field, TextType::class);
            }

            $form = $builder->getForm();
            self::assertInstanceOf(Form::class, $form);

            return $form;
        });

        $component = new ProductDetails(
            self::createStub(DataAccessService::class),
            self::createStub(ProductSaleElementsAccessService::class),
            $formService,
            new CartFacade(
                $cartItems,
                self::createStub(CartSelectionService::class),
                self::createStub(PostageHandler::class),
                $cartRetriever,
                new CartGiftWrappingService(new GiftWrappingProvider(self::createStub(TaxCalculatorFactoryInterface::class))),
            ),
            new RequestStack(),
            new RunningSaleResolver(self::createStub(DataAccessService::class)),
            new AltTextResolver(),
        );
        $component->setLiveResponder(new LiveResponder());
        $component->virtual = true;
        $component->productId = 1;
        $component->currentPse = ['id' => 7, 'quantity' => 3];
        $component->formValues = ['product' => 1, 'product_sale_elements_id' => 7, 'quantity' => 1, 'append' => 1, 'newness' => 0];

        return $component;
    }
}
