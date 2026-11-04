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

use FlexyBundle\AccountMenu\NativeAccountMenuItemProvider;
use FlexyBundle\Service\CheckoutStockRefusal;
use FlexyBundle\Service\TaxDisplay;
use FlexyBundle\Twig\PriceDisplayExtension;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Thelia\Domain\Order\Exception\StockShortageException;
use Thelia\Test\IntegrationTestCase;

/**
 * The sentences the theme translates in PHP are read from its own catalogue, as the ones of its
 * templates: the mention next to a price and the stock refusal of the checkout stayed in English on
 * a shop in French, their services having received the core's translator. Runs on the real container.
 */
final class ThemeSentenceTranslationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $translator = self::getContainer()->get('translator');
        \assert($translator instanceof LocaleAwareInterface);
        $translator->setLocale('fr_FR');
    }

    public function testTheMentionNextToAPriceIsInTheLanguageOfTheShop(): void
    {
        $extension = self::getContainer()->get(PriceDisplayExtension::class);
        \assert($extension instanceof PriceDisplayExtension);

        self::assertSame('TTC', $extension->taxMention(TaxDisplay::Taxed));
    }

    public function testTheStockRefusalOfTheCheckoutIsInTheLanguageOfTheShop(): void
    {
        $refusal = self::getContainer()->get(CheckoutStockRefusal::class);
        \assert($refusal instanceof CheckoutStockRefusal);

        $redirect = $refusal->answer(new StockShortageException(), '/cart', null);

        self::assertStringStartsWith('Certains produits de votre panier', $redirect->getMessage());
    }

    public function testTheAccountMenuIsInTheLanguageOfTheShop(): void
    {
        $provider = self::getContainer()->get(NativeAccountMenuItemProvider::class);
        \assert($provider instanceof NativeAccountMenuItemProvider);

        $texts = array_map(static fn ($item): string => $item->text, iterator_to_array($provider->getItems(), false));

        self::assertContains('Mon profil', $texts);
    }
}
