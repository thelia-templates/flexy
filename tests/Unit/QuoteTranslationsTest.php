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
 * The sentences of the quote pages are written in English in the templates and carried by the catalogs:
 * one missing from a catalog reaches the buyer untranslated. English answers itself, French and German
 * are what the shop of a buyer in those languages reads. Every sentence the templates and components of
 * the quotes translate is looked for in the catalogs, so that a new one cannot be forgotten.
 */
final class QuoteTranslationsTest extends TestCase
{
    private const array LOCALES = ['en_US', 'fr_FR', 'de_DE'];

    /** Words the languages write as one another does. */
    private const array SAME_IN_OTHER_LANGUAGES = ['Revision', 'Revision %number%'];

    /**
     * Sentences the quote code hands to a component as a text, or words from the core, where the literal and its filter do not
     * stand side by side: the pages that print them are not quote templates, and the catalogs carry them all the same.
     */
    private const array SENTENCES_HANDED_OVER = [
        'This product could not be added: the cart in hand is the cart of an accepted quote. Go back to your own cart to add it.',
        'None of these lines could be added: the cart in hand is the cart of an accepted quote. Go back to your own cart to add them.',
        'This shop only sells through quotes: ask for a quote for this cart.',
        'The carrier of a quote is the one it was agreed with and cannot be changed.',
        'The delivery address of a quote is the one it was agreed with and cannot be changed.',
        'The delivery address of your quote',
        'The carrier of your quote',
        'The carrier of your quote does not deliver to this address.',
        'Your delivery address',
        'Your delivery method',
        'This quote has no such revision.',
        'The cart of a quote cannot be turned into a new request for a quote.',
        'The cart of this quote no longer holds what was accepted: accept the quote again.',
        'Your company account is suspended: quotes cannot be asked for or answered until it is reactivated.',
        'Some lines of this quote are no longer sold, so it cannot be accepted.',
        'The currency of this quote is no longer available, so it cannot be accepted.',
        'An order was already placed for this quote.',
        'The quote is not in a state that allows this.',
        'The quote was not found.',
    ];

    private const array TEMPLATES = [
        'account-quotes.html.twig',
        'account-quote.html.twig',
        'account-quote-request.html.twig',
        'account-quote-history.html.twig',
        'account-quote-revision.html.twig',
        'components/Molecules/QuoteState/Base.html.twig',
        'components/Organisms/QuoteActions/Base.html.twig',
        'components/Organisms/QuoteCartExit/Base.html.twig',
        'components/Organisms/QuoteCard/Base.html.twig',
        'components/Organisms/QuoteCounterOffer/Base.html.twig',
        'components/Organisms/QuoteLines/Base.html.twig',
        'components/Organisms/QuoteRequestButton/Base.html.twig',
    ];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sentences(): iterable
    {
        foreach (self::LOCALES as $locale) {
            foreach (self::sentencesOfTheTemplates() as $sentence) {
                yield $locale.' / '.$sentence => [$locale, $sentence];
            }
        }
    }

    #[DataProvider('sentences')]
    public function testTheCatalogCarriesTheSentence(string $locale, string $sentence): void
    {
        $catalog = Yaml::parseFile(self::theme().'/translations/messages.'.$locale.'.yaml');

        self::assertArrayHasKey($sentence, $catalog, $locale);
        self::assertNotSame('', trim((string) $catalog[$sentence]));

        if ('en_US' !== $locale && !\in_array($sentence, self::SAME_IN_OTHER_LANGUAGES, true)) {
            self::assertNotSame($sentence, $catalog[$sentence], 'The catalog does not repeat the English sentence.');
        }
    }

    public function testTheTemplatesTranslateSomething(): void
    {
        self::assertGreaterThan(30, \count(self::sentencesOfTheTemplates()));
    }

    /**
     * @return list<string>
     */
    private static function sentencesOfTheTemplates(): array
    {
        $sentences = [];

        foreach (self::TEMPLATES as $template) {
            $source = (string) file_get_contents(self::theme().'/'.$template);
            preg_match_all("/'((?:[^'\\\\]|\\\\.)+)'\\|trans\\b/", $source, $matches);

            foreach ($matches[1] as $sentence) {
                $sentences[] = stripslashes($sentence);
            }
        }

        // The labels the pill picks by state are translated where the map is written, with the same filter.
        return array_values(array_unique([...$sentences, ...self::SENTENCES_HANDED_OVER]));
    }

    private static function theme(): string
    {
        return \dirname(__DIR__, 2);
    }
}
