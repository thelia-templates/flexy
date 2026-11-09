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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * What a buyer is told instead of, or beside, a price is written in every language the theme ships
 * complete: the invitation to sign in, the request for a price, the sentence of an item that is not
 * on sale to them, the line of a tier and the start of the message of a price request. The French wording is the one the shop was specified with.
 */
final class PriceDisplayModeTranslationsTest extends TestCase
{
    private const array SENTENCES = [
        'Log in to see the prices',
        'Price on request',
        'Request a price',
        'Not available for purchase',
        'From %quantity%:',
        'Price request for %reference%',
        'Price by quantity',
    ];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sentences(): iterable
    {
        foreach (['en_US', 'fr_FR', 'de_DE'] as $locale) {
            foreach (self::SENTENCES as $sentence) {
                yield $locale.' '.$sentence => [$locale, $sentence];
            }
        }
    }

    #[DataProvider('sentences')]
    public function testEverySentenceIsTranslated(string $locale, string $sentence): void
    {
        $catalog = Yaml::parseFile(\dirname(__DIR__, 2).'/translations/messages.'.$locale.'.yaml');

        self::assertArrayHasKey($sentence, $catalog);
        self::assertNotSame('', trim((string) $catalog[$sentence]));

        if ('en_US' !== $locale) {
            self::assertNotSame($sentence, $catalog[$sentence], 'Left in English.');
        }
    }

    public function testTheFrenchWordingIsTheSpecifiedOne(): void
    {
        $catalog = Yaml::parseFile(\dirname(__DIR__, 2).'/translations/messages.fr_FR.yaml');

        self::assertSame('Connectez-vous pour voir les prix', $catalog['Log in to see the prices']);
        self::assertSame('Demander un tarif', $catalog['Request a price']);
        self::assertSame("Non disponible à l'achat", $catalog['Not available for purchase']);
        self::assertSame('À partir de %quantity% :', $catalog['From %quantity%:']);
        self::assertSame('Demande de tarif pour %reference%', $catalog['Price request for %reference%']);
    }
}
