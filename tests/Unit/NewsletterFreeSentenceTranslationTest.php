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
 * The account benefits come in two sentences, with and without the newsletter, chosen by the
 * switch of the shop: both are translated, and the one without the newsletter never cites it.
 */
final class NewsletterFreeSentenceTranslationTest extends TestCase
{
    private const string WITH = 'With your account, you can track your orders, manage your profile, receive the newsletter, manage your wish lists...';
    private const string WITHOUT = 'With your account, you can track your orders, manage your profile, manage your wish lists...';

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        foreach (['en_US', 'fr_FR', 'de_DE'] as $locale) {
            yield $locale => [$locale];
        }
    }

    #[DataProvider('locales')]
    public function testTheSentenceWithoutTheNewsletterIsTranslatedAndDoesNotCiteIt(string $locale): void
    {
        $catalog = Yaml::parseFile(\dirname(__DIR__, 2).'/translations/messages.'.$locale.'.yaml');

        self::assertArrayHasKey(self::WITH, $catalog);
        self::assertArrayHasKey(self::WITHOUT, $catalog);
        self::assertNotSame('', trim((string) $catalog[self::WITHOUT]));
        self::assertStringNotContainsStringIgnoringCase('newsletter', (string) $catalog[self::WITHOUT]);
        self::assertStringContainsStringIgnoringCase('newsletter', (string) $catalog[self::WITH]);
    }
}
