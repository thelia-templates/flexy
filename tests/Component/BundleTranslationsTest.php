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

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Order\Exception\BundleLineNotOrderableException;
use Thelia\Domain\Order\Exception\OrderPlacementConflictException;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;

/**
 * The words the bundles add to the theme, in the three languages it ships them in. The sentence of the core that
 * refuses to return a component on its own is one of them: the return form prints it through the translator of the
 * theme (AccountOrderController::returnRequest), so it has to be in the catalogue or it reads in English.
 *
 * The `messages` domain is shared with the back office theme, which already says two of these sentences: the French
 * ones are the same words, so the wording does not depend on which catalogue the translator reads last.
 */
final class BundleTranslationsTest extends KernelTestCase
{
    /**
     * The refusal the checkout raises for a pack that can no longer be ordered: the controller of the theme answers it with
     * this sentence from its own catalogue (the core words it in a catalogue the theme does not feed).
     */
    private const REFUSAL = 'The pack %ref can no longer be ordered as it is in your cart. Please remove it and add it again.';

    /**
     * The refusal of an order that lost a lock tie with a concurrent one.
     */
    private const CONFLICT = 'Another order took the same products at the same moment. Please try again.';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sentences(): iterable
    {
        $expected = [
            'fr_FR' => [
                'Included in the bundle' => 'Inclus dans le pack',
                'You save %amount%' => 'Vous économisez 72,00 €',
                'Optional' => 'Optionnel',
                'Bundle composition' => 'Composition du pack',
                'This bundle is currently unavailable' => "Ce pack n'est pas disponible actuellement",
                ReturnEligibilityChecker::BUNDLE_COMPONENT_NOT_RETURNABLE_MESSAGE => "Cet article fait partie d'un pack et ne peut pas être retourné seul.",
                'Include %title% in the bundle' => 'Ajouter Chain lock au pack',
                self::REFUSAL => "Le pack PACK-1 ne peut plus être commandé tel qu'il est dans votre panier. Veuillez le retirer et l'ajouter à nouveau.",
                self::CONFLICT => 'Une autre commande a pris les mêmes produits au même moment. Veuillez réessayer.',
            ],
            'de_DE' => [
                'Included in the bundle' => 'Im Bundle enthalten',
                'You save %amount%' => 'Sie sparen 72,00 €',
                'Optional' => 'Optional',
                'Bundle composition' => 'Zusammensetzung des Bundles',
                'This bundle is currently unavailable' => 'Dieses Bundle ist derzeit nicht verfügbar',
                ReturnEligibilityChecker::BUNDLE_COMPONENT_NOT_RETURNABLE_MESSAGE => 'Dieser Artikel gehört zu einem Bundle und kann nicht einzeln zurückgesendet werden.',
                'Include %title% in the bundle' => 'Chain lock zum Bundle hinzufügen',
                self::REFUSAL => 'Das Bundle PACK-1 kann nicht mehr so bestellt werden, wie es in Ihrem Warenkorb liegt. Bitte entfernen Sie es und fügen Sie es erneut hinzu.',
                self::CONFLICT => 'Eine andere Bestellung hat zur selben Zeit dieselben Produkte erhalten. Bitte versuchen Sie es erneut.',
            ],
            'en_US' => [
                'Included in the bundle' => 'Included in the bundle',
                'You save %amount%' => 'You save 72,00 €',
                'Optional' => 'Optional',
                'Bundle composition' => 'Bundle composition',
                'This bundle is currently unavailable' => 'This bundle is currently unavailable',
                ReturnEligibilityChecker::BUNDLE_COMPONENT_NOT_RETURNABLE_MESSAGE => 'This item is part of a bundle and cannot be returned on its own.',
                'Include %title% in the bundle' => 'Include Chain lock in the bundle',
                self::REFUSAL => 'The pack PACK-1 can no longer be ordered as it is in your cart. Please remove it and add it again.',
                self::CONFLICT => 'Another order took the same products at the same moment. Please try again.',
            ],
            'es_ES' => [
                'Included in the bundle' => 'Incluido en el pack',
                'Optional' => 'Opcional',
                'Bundle composition' => 'Composición del pack',
                'Include %title% in the bundle' => 'Añadir Chain lock al pack',
                ReturnEligibilityChecker::BUNDLE_COMPONENT_NOT_RETURNABLE_MESSAGE => 'Este artículo forma parte de un pack y no se puede devolver por separado.',
                self::REFUSAL => 'El pack PACK-1 ya no se puede pedir tal como está en su carrito. Retírelo y añádalo de nuevo.',
                self::CONFLICT => 'Otro pedido ha reservado los mismos productos al mismo tiempo. Inténtelo de nuevo.',
            ],
            'it_IT' => [
                'Included in the bundle' => 'Incluso nel pack',
                'Optional' => 'Facoltativo',
                'Bundle composition' => 'Composizione del pack',
                'Include %title% in the bundle' => 'Aggiungi Chain lock al pack',
                ReturnEligibilityChecker::BUNDLE_COMPONENT_NOT_RETURNABLE_MESSAGE => 'Questo articolo fa parte di un pack e non può essere reso singolarmente.',
                self::REFUSAL => "Il pack PACK-1 non può più essere ordinato così com'è nel carrello. Rimuovilo e aggiungilo di nuovo.",
                self::CONFLICT => 'Un altro ordine ha preso gli stessi prodotti nello stesso momento. Riprova.',
            ],
        ];

        foreach ($expected as $locale => $sentences) {
            foreach ($sentences as $key => $translation) {
                yield $locale.' '.$key => [$locale, $key, $translation];
            }
        }
    }

    #[DataProvider('sentences')]
    public function testEverySentenceIsTranslated(string $locale, string $key, string $expected): void
    {
        $translator = self::getContainer()->get('translator');
        \assert($translator instanceof TranslatorInterface);

        self::assertSame($expected, $translator->trans($key, ['%amount%' => '72,00 €', '%title%' => 'Chain lock', '%ref' => 'PACK-1'], null, $locale));
    }

    /**
     * The sentence the theme translates is the one the core raises: if the core rewords its exception, the catalogue of the
     * theme has to follow, or the buyer reads the core's English under a French page.
     */
    public function testTheSentenceOfTheThemeIsTheOneTheCoreRaises(): void
    {
        $refusal = new BundleLineNotOrderableException('PACK-1', 12);

        $translator = self::getContainer()->get('translator');
        \assert($translator instanceof TranslatorInterface);

        self::assertSame($refusal->getMessage(), $translator->trans(self::REFUSAL, ['%ref' => 'PACK-1'], null, 'en_US'));
    }

    public function testTheConflictSentenceIsTheOneTheCoreRaises(): void
    {
        $translator = self::getContainer()->get('translator');
        \assert($translator instanceof TranslatorInterface);

        self::assertSame((new OrderPlacementConflictException())->getMessage(), $translator->trans(self::CONFLICT, [], null, 'en_US'));
    }
}
